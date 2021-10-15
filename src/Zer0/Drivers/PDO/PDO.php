<?php

namespace Zer0\Drivers\PDO;

use Zer0\Drivers\Traits\QueryLog;
use Zer0\PDO\Exceptions\QueryFailedException;

/**
 * Class PDO
 *
 * @package Zer0\Drivers\PDO
 */
class PDO extends \PDO
{
    use QueryLog;

    /**
     * @var \PDO
     */
    protected $instance;

    /**
     * @var array
     */
    protected $construct;

    /**
     * @var bool
     */
    protected $setNamesSent = false;

    /**
     * @var
     * `  */
    protected $driverName;

    /**
     * @var bool
     */
    protected $initialized = false;

    /**
     * PDO constructor.
     *
     * @param string      $dsn
     * @param null|string $username
     * @param null|string $passwd
     * @param array       $options
     * @param bool        $logging
     */
    public function __construct (string $dsn, ?string $username = null, ?string $passwd = null, array $options = [], bool $logging = false)
    {
        if ($logging) {
            $this->queryLogging = true;
        }
        $this->construct = [$dsn, $username, $passwd, $options];
    }

    /**
     * @param int   $attribute
     * @param mixed $value
     *
     * @return bool|void
     */
    public function setAttribute ($attribute, $value)
    {
        if (!$this->initialized) {
            $this->init();
        }

        return parent::setAttribute($attribute, $value);
    }

    /**
     * @param string $string
     * @param int    $parameter_type
     *
     * @return false|string
     */
    public function quote ($string, $parameter_type = \PDO::PARAM_STR)
    {
        if (!$this->initialized) {
            $this->init();
        }

        return \PDO::quote($string, $parameter_type);
    }

    /**
     *
     */
    public function init (): void
    {
        if ($this->initialized) {
            return;
        }
        $this->initialized = true;
        if ($this->queryLogging) {
            $t0 = microtime(true);
            parent::__construct(...$this->construct);
            $this->queryLog[] = [
                'sql'  => 'CONNECT',
                'time' => microtime(true) - $t0,
            ];
        }
        else {
            parent::__construct(...$this->construct);
        }
        $this->driverName = $this->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, false);
        if ($this->driverName === 'mysql') {
            $this->setAttribute(\PDO::MYSQL_ATTR_MULTI_STATEMENTS, false);
        }
    }

    /**
     * @param string $table
     * @param array  $row
     *
     * @return \PDOStatement
     * @throws QueryFailedException
     */
    public function insert (string $table, array $row): \PDOStatement
    {
        $fields = '';
        $values = '';
        foreach ($row as $field => $value) {
            $fields .= ($fields !== '' ? ', ' : '') . $field;
            $values .= ($values !== '' ? ', ' : '') . $this->quote($value);
        }

        return $this->query('INSERT INTO ' . $table . ' (' . $fields . ') VALUES (' . $values . ')');
    }

    /**
     * @param string   $query
     * @param int|null $fetchMode
     * @param mixed    ...$fetchModeArgs
     *
     * @return false|\PDOStatement|void
     */
    public function query (string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs)
    {
        if (!$this->initialized) {
            $this->init();
        }
        if ($this->queryLogging) {
            $t0 = microtime(true);
        }
        try {
            if ($this->instance !== null) {
                return $stmt = $this->instance->query($query);
            }
            else {
                return $stmt = parent::query($query);
            }
        } catch (\PDOException $e) {
            $message = $e->getMessage();
            if (strpos($message, 'server has gone away') !== false ||
                strpos($message, 'Error reading result set') !== false ||
                strpos($message, 'no connection to the server') !== false ||
                strpos($message, 'MySQL server has gone away') !== false) {
                $this->instance = new self(...$this->construct);

                return $stmt = $this->instance->query($query);
            }
            throw new QueryFailedException('SQL query has failed: ' . $query, 1, $e);
        } finally {
            if ($this->queryLogging && isset($stmt)) {
                $this->queryLog[] = [
                    'sql'  => $query,
                    'time' => microtime(true) - $t0,
                ];
            }
        }
    }

    /**
     * Execute SQL query
     *
     * @param string $sql    Query
     * @param array  $values = [] Array of values
     *
     * @return \PDOStatement
     * @throws QueryFailedException
     */
    public function queryBind (string $sql, array $values = []): \PDOStatement
    {
        if (!$this->initialized) {
            $this->init();
        }

        if ($this->queryLogging) {
            $t0 = microtime(true);
        }
        if ($values) {
            $sql = $this->replacePlaceholders($sql, $values);
        }
        try {
            if ($this->instance !== null) {
                return $stmt = $this->instance->query($sql);
            }
            else {
                return $stmt = parent::query($sql);
            }
        } catch (\PDOException $e) {
            $message = $e->getMessage();
            if (strpos($message, 'server has gone away') !== false ||
                strpos($message, 'Error reading result set') !== false ||
                strpos($message, 'no connection to the server') !== false ||
                strpos($message, 'MySQL server has gone away') !== false) {
                $this->instance = new self(...$this->construct);

                return $stmt = $this->instance->query($sql);
            }
            throw new QueryFailedException('SQL query has failed: ' . $sql, 1, $e);
        } finally {
            if ($this->queryLogging && isset($stmt)) {
                $this->queryLog[] = [
                    'sql'  => $sql,
                    'time' => microtime(true) - $t0,
                ];
            }
        }
    }

    /**
     * Resolves placeholders in given SQL query. Values corresponding to individual placeholders can be arrays,
     * in which case they are converted to IN() clauses.
     *
     * @param string $sql    Query
     * @param array  $values Array of values or key/value pairs (if using named placeholders). Values can be
     *                       arrays, and will be converted to IN().
     *
     * @return string
     * @example ('SELECT * FROM table WHERE id = ?', [])                   ;
     *          returns 'SELECT * FROM table WHERE id = NULL'
     *
     * @example ('SELECT * FROM table WHERE id = :ids', ['ids' => [4,5,6]]);
     *          returns 'SELECT * FROM table WHERE id IN(4, 5, 6)'
     *
     * @example ('SELECT * FROM table WHERE id IN(:ids)                    ', ['ids' => [4,5,6]]);
     *          returns 'SELECT * FROM table WHERE id IN(4, 5, 6)'
     *
     * @example ('SELECT * FROM table WHERE id = :id', ['id' => 1])        ;
     *          returns 'SELECT * FROM table WHERE id = 1'
     *
     * @example ('SELECT * FROM table WHERE id = ?', [1])                  ;
     *          returns 'SELECT * FROM table WHERE id = 1'
     *
     * @example ('SELECT * FROM table WHERE id = ?', [[1,2,3]])            ;
     *          returns 'SELECT * FROM table WHERE id IN(1, 2, 3)'
     *
     */
    public function replacePlaceholders (string $sql, ?array $values = null): string
    {
        if ($values === null) {
            return $sql;
        }
        if (is_scalar($values)) {
            $values = [$values];
        }

        // Current position in values array
        $pos = 0;

        // Regex matches '?', ':foo', 'IN(?)', '= ?', '= :foo'
        $sql = preg_replace_callback(
            '~'
            . '(\?|:\w+)'
            . '|\s*(?<![<>])(!?=)\s*(\?|:\w+)'
            . '|\s*(NOT)?\s*IN\s*\((\?|:\w+)\)'
            . '~i',
            function ($matches) use ($values, &$pos) {
                if (isset($matches[1]) && $matches[1] !== '') {
                    // ? or :foo
                    $placeholder = $matches[0];
                    $in          = 0;
                }
                else if (isset($matches[3]) && $matches[3] !== '') {
                    // = ... or != ...
                    $placeholder = $matches[3];
                    // Treat as IN, since vals could be array
                    $in = $matches[2] === '!=' ? -1 : 1;
                }
                if (isset($matches[5])) {
                    // IN(...) or NOT IN(...)
                    $placeholder = $matches[5];
                    $in          = strlen($matches[4]) ? -1 : 1;
                }

                // Get value for unnamed or named placeholder respectively (indexed vs. assoc. array)
                if ($placeholder === '?') {
                    $dummy =& $values[$pos++];
                }
                else {
                    $placeholder = substr($placeholder, 1); // omit ':' prefix
                    $dummy       =& $values[$placeholder];
                }
                $val = $dummy;

                // Make {placeholder} = NULL in case of IN with no valuess
                if ($val === null) {
                    if ($in === 0) {
                        $prefix = '';
                    }
                    else if ($in === 1) {
                        $prefix = ' = ';
                    }
                    else {
                        $prefix = ' != ';
                    }

                    return $prefix . 'NULL';
                }

                if (is_array($val)) {
                    // Transform IN(NULL) =>  = NULL
                    if ($in !== 0 && !count($val)) {
                        if ($in === 1) {
                            $prefix = ' = ';
                        }
                        else {
                            $prefix = ' != ';
                        }

                        return $prefix . 'NULL'; // @TODO: think about empty IN()
                    }

                    if (count($val) === 1) {
                        // Set to scalar val for equality clause ( = val1)
                        $val = current($val);
                    }
                    else {
                        // Multiple vals, so generate IN(val1, val2, ...) clause
                        if ($in !== 0) {
                            if ($in === 1) {
                                $clause = ' IN(';
                            }
                            else {
                                $clause = ' NOT IN(';
                            }
                        }
                        else {
                            // WHERE (val1, val2, .. ) @TODO Is this even valid SQL?
                            $clause = ' (';
                        }
                        $i = 0;

                        // Concatenate IN values
                        foreach ($val as $v) {
                            if ($v === null) {
                                $v = 'NULL';
                            }
                            else if (!is_integer($v) && !is_object($v)) {
                                $v = $this->quote($v);
                            }
                            $clause .= ($i++ > 0 ? ', ' : '') . $v;
                        }
                        $clause .= ')';

                        return $clause;
                    }
                }
                $valType = gettype($val);
                // Generate simple equality clause;

                if ($valType === 'object') {
                    $val = $val->toString($this);
                }
                else if ($valType === 'boolean') {
                    if ($this->driverName === 'pgsql') {
                        $val = $val ? 'true' : 'false';
                    }
                    else {
                        $val = $val ? '1' : '0';
                    }
                }
                else if ($valType !== 'integer') {
                    $val = $this->quote($val);
                }
                if ($in === 0) {
                    $prefix = '';
                }
                else if ($in === 1) {
                    $prefix = ' = ';
                }
                else {
                    $prefix = ' != ';
                }

                return $prefix . $val;
            },
            $sql
        );

        return $sql;
    }
}

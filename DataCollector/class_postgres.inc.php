<?php
/**
 * Thin php-pgsql wrapper matching the Oracle helper style used on this dashboard.
 */
class Postgres
{
    /** @var resource|\PgSql\Connection|null */
    private $conn = null;

    public function connect($host, $port, $dbname, $user, $password)
    {
        $parts = array(
            'host=' . $host,
            'port=' . $port,
            'dbname=' . $dbname,
            'user=' . $user,
        );
        if ($password !== null && $password !== '') {
            $parts[] = 'password=' . $password;
        }

        $this->conn = @pg_connect(implode(' ', $parts));
        if (!$this->conn) {
            throw new Exception('PostgreSQL connection failed');
        }
    }

    /**
     * @return resource|\PgSql\Result
     */
    public function ExecSQL($query)
    {
        $this->requireConnection();
        $result = pg_query($this->conn, $query);
        if ($result === false) {
            throw new Exception('PostgreSQL query failed: ' . pg_last_error($this->conn));
        }
        return $result;
    }

    /**
     * @return array|false  Associative row with UPPERCASE keys, or false at EOF.
     */
    public function FetchRow($result)
    {
        $row = pg_fetch_assoc($result);
        if ($row === false) {
            return false;
        }
        return array_change_key_case($row, CASE_UPPER);
    }

    /**
     * @return array[]
     */
    public function FetchAll($result)
    {
        $rows = array();
        while ($row = $this->FetchRow($result)) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function Disconnect()
    {
        if ($this->conn) {
            pg_close($this->conn);
            $this->conn = null;
        }
    }

    public function IsConnected()
    {
        return $this->conn !== null;
    }

    /**
     * Same physical target as another connection config?
     */
    public static function SameTarget($host, $port, $dbname, $user, $other_host, $other_port, $other_dbname, $other_user)
    {
        return (string) $host === (string) $other_host
            && (string) $port === (string) $other_port
            && (string) $dbname === (string) $other_dbname
            && (string) $user === (string) $other_user;
    }

    private function requireConnection()
    {
        if (!$this->conn) {
            throw new Exception('PostgreSQL is not connected');
        }
    }
}

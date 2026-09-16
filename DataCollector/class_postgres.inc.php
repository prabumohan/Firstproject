<?php

/**
 * Lightweight PostgreSQL helper — similar call style to the existing Oracle class.
 * Requires PHP pgsql extension (php-pgsql).
 */
class Postgres
{
    /** @var resource|false */
    private $conn = false;

    /**
     * @throws Exception
     */
    public function connect($host, $port, $dbname, $user, $password)
    {
        $conn_str = sprintf(
            'host=%s port=%s dbname=%s user=%s password=%s connect_timeout=10',
            $host,
            $port,
            $dbname,
            $user,
            $password
        );

        $this->conn = @pg_connect($conn_str);
        if ($this->conn === false) {
            throw new Exception('PostgreSQL connection failed: ' . $this->lastError());
        }
    }

    /** @return string */
    public function PrepareSQL($query)
    {
        return $query;
    }

    /**
     * @return resource
     * @throws Exception
     */
    public function ExecSQL($query)
    {
        $result = @pg_query($this->conn, $query);
        if ($result === false) {
            throw new Exception('PostgreSQL query failed: ' . $this->lastError());
        }

        return $result;
    }

    /**
     * Returns associative row with UPPERCASE keys (to match Oracle FetchRow output).
     *
     * @param resource $result
     * @return array<string, mixed>|false
     */
    public function FetchRow($result)
    {
        $row = pg_fetch_assoc($result);
        if ($row === false) {
            return false;
        }

        return array_change_key_case($row, CASE_UPPER);
    }

    public function Disconnect()
    {
        if ($this->conn !== false) {
            pg_close($this->conn);
            $this->conn = false;
        }
    }

    /** @return string */
    private function lastError()
    {
        $err = $this->conn !== false ? pg_last_error($this->conn) : pg_last_error();
        return $err !== false ? $err : 'unknown error';
    }
}

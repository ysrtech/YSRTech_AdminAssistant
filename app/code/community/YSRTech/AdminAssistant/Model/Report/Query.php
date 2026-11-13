<?php
/**
 * Runs a report query on its own connection, as a database user that can
 * only read.
 *
 * Before each query: the user's grants are read and the query is refused if
 * the user can change anything. Then the session is made read-only, given a
 * statement time limit and a known SQL mode, and the query runs inside a
 * read-only transaction that is rolled back. Columns that hold secrets are
 * dropped from what comes back, whatever the query called them.
 */
class YSRTech_AdminAssistant_Model_Report_Query
{
    /** Privileges a read-only user may hold */
    public const SAFE_PRIVILEGES = ['SELECT', 'USAGE', 'SHOW VIEW'];

    /** @var Varien_Db_Adapter_Pdo_Mysql|Zend_Db_Adapter_Abstract|null */
    private $_connection;

    /**
     * Use this connection rather than building one. For tests.
     */
    public function setConnection($connection): self
    {
        $this->_connection = $connection;

        return $this;
    }

    public function configured(): bool
    {
        $h = Mage::helper('ysrtech_adminassistant');

        return (bool) $h->getConfig('report/enabled')
            && trim((string) $h->getConfig('report/username')) !== '';
    }

    /**
     * @return Varien_Db_Adapter_Pdo_Mysql|Zend_Db_Adapter_Abstract
     */
    public function connection()
    {
        if ($this->_connection) {
            return $this->_connection;
        }

        $h = Mage::helper('ysrtech_adminassistant');

        if (!$this->configured()) {
            Mage::throwException('The report query is not set up. An administrator can turn it on under System > Configuration > YSRTech > AI Assistant > Report Query, with a read-only database user.');
        }

        $main = Mage::getConfig()->getResourceConnectionConfig('default_setup');
        $host = trim((string) $h->getConfig('report/host')) ?: (string) $main->host;
        $user = trim((string) $h->getConfig('report/username'));

        if ($user === (string) $main->username) {
            Mage::throwException('The report query user must not be the store\'s own database user, which can change everything.');
        }

        $this->_connection = new Varien_Db_Adapter_Pdo_Mysql([
            'host'     => $host,
            'username' => $user,
            'password' => $h->getSecret('report/password'),
            'dbname'   => (string) $main->dbname,
            'type'     => 'pdo_mysql',
            'model'    => 'mysql4',
            'active'   => '1',
            'initStatements' => 'SET NAMES utf8mb4',
        ]);

        return $this->_connection;
    }

    /**
     * What the connected user may do, read from its grants.
     *
     * @return array{read_only:bool, unsafe:string[], whole_database:bool, grants:string[]}
     */
    public function inspectGrants(): array
    {
        $grants = $this->connection()->fetchCol('SHOW GRANTS FOR CURRENT_USER()');

        return self::readGrants($grants);
    }

    /**
     * @param  string[] $grants Lines of SHOW GRANTS
     * @return array{read_only:bool, unsafe:string[], whole_database:bool, grants:string[]}
     */
    public static function readGrants(array $grants): array
    {
        $unsafe = [];
        $whole  = false;

        foreach ($grants as $line) {
            $line = (string) $line;

            if (stripos($line, 'GRANT') !== 0 || stripos($line, 'PROXY ON') !== false) {
                if (stripos($line, 'PROXY ON') !== false) {
                    $unsafe[] = 'PROXY';
                }
                continue;
            }

            if (stripos($line, 'WITH GRANT OPTION') !== false || stripos($line, 'WITH ADMIN OPTION') !== false) {
                $unsafe[] = 'GRANT OPTION';
            }

            // A role granted to the user ("GRANT `role` TO `user`") carries
            // privileges this cannot see; refuse it rather than guess
            if (!preg_match('/^GRANT\s+(.+?)\s+ON\s+(.+?)\s+TO\s/i', $line, $m)) {
                $unsafe[] = 'ROLE (' . mb_substr($line, 0, 80) . ')';
                continue;
            }

            // Column-level grants look like SELECT (a, b); the parentheses go
            $privileges = preg_replace('/\([^)]*\)/', '', $m[1]);
            $object     = trim($m[2]);

            foreach (array_map('trim', explode(',', $privileges)) as $privilege) {
                $privilege = strtoupper(preg_replace('/\s+/', ' ', $privilege));

                if ($privilege !== '' && !in_array($privilege, self::SAFE_PRIVILEGES, true)) {
                    $unsafe[] = $privilege . ' ON ' . $object;
                }

                if ($privilege === 'SELECT' && preg_match('/\.\s*\*$/', $object)) {
                    $whole = true;
                }
            }
        }

        return ['read_only' => !$unsafe, 'unsafe' => array_values(array_unique($unsafe)), 'whole_database' => $whole, 'grants' => array_map('strval', $grants)];
    }

    /**
     * @return array{columns:string[], rows:array<int, array>, truncated:bool, dropped_columns:string[], ms:int}
     * @throws Mage_Core_Exception
     */
    public function run(string $sql): array
    {
        $h     = Mage::helper('ysrtech_adminassistant');
        $cap   = max(1, min(1000, (int) $h->getConfig('report/row_cap') ?: 200));
        $limit = max(1, min(60, (int) $h->getConfig('report/time_limit') ?: 5));
        $guard = Mage::getSingleton('ysrtech_adminassistant/report_guard');
        $ready = $guard->prepare($sql, $cap);
        $db    = $this->connection();

        try {
            $grants = $this->inspectGrants();
        } catch (Zend_Db_Exception | PDOException $e) {
            Mage::throwException('The read-only database user could not connect: ' . mb_substr($e->getMessage(), 0, 160));
        }

        if (!$grants['read_only']) {
            Mage::throwException('Refused: the report query\'s database user can change data (' . implode(', ', array_slice($grants['unsafe'], 0, 4)) . '). Give it SELECT only.');
        }

        $version = (string) $db->fetchOne('SELECT VERSION()');

        // A known SQL mode: no ANSI_QUOTES, no NO_BACKSLASH_ESCAPES, so the
        // server reads the query exactly as the guard did
        $db->query("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $db->query('SET SESSION TRANSACTION READ ONLY');

        if (stripos($version, 'mariadb') !== false) {
            $db->query('SET SESSION max_statement_time = ' . (int) $limit);
        } else {
            $db->query('SET SESSION MAX_EXECUTION_TIME = ' . (int) ($limit * 1000));
        }

        $started = microtime(true);
        $db->query('START TRANSACTION READ ONLY');

        try {
            $rows = $db->fetchAll($ready['sql']);
        } catch (Throwable $e) {
            $message = $e->getMessage();

            if (stripos($message, 'max_statement_time') !== false || stripos($message, 'maximum statement execution time') !== false || stripos($message, 'interrupted') !== false) {
                Mage::throwException(sprintf('The query took longer than %d seconds and was stopped. Narrow it with a date range or an index column.', $limit));
            }

            // The database's own message helps the model fix its SQL; it holds no data
            Mage::throwException('The database refused the query: ' . mb_substr(preg_replace('/SQLSTATE\[\w+\]:?\s*/', '', $message), 0, 300));
        } finally {
            try {
                $db->query('ROLLBACK');
            } catch (Throwable $e) {
                // the connection is discarded with the request
            }
        }

        $ms        = (int) ((microtime(true) - $started) * 1000);
        $truncated = count($rows) > $cap;
        $rows      = array_slice($rows, 0, $cap);
        $sanitizer = Mage::getSingleton('ysrtech_adminassistant/sanitizer');
        $columns   = $rows ? array_keys($rows[0]) : [];
        $dropped   = array_values(array_filter($columns, static fn ($c) => YSRTech_AdminAssistant_Model_Report_Guard::isDeniedColumn((string) $c) || $sanitizer->isSecretKey((string) $c)));

        if ($dropped) {
            foreach ($rows as &$row) {
                foreach ($dropped as $column) {
                    unset($row[$column]);
                }
            }
            unset($row);
            $columns = array_values(array_diff($columns, $dropped));
        }

        return ['columns' => $columns, 'rows' => $rows, 'truncated' => $truncated, 'dropped_columns' => $dropped, 'ms' => $ms, 'tables' => $ready['tables']];
    }

    /**
     * The statements an administrator runs to create the user, table by table.
     */
    public function grantStatements(string $user = 'store_ai_readonly', string $host = 'localhost'): string
    {
        $main   = Mage::getConfig()->getResourceConnectionConfig('default_setup');
        $dbname = str_replace('`', '', (string) $main->dbname);
        $quote  = static fn ($s) => "'" . str_replace("'", "''", $s) . "'";
        $lines  = [sprintf('CREATE USER %s@%s IDENTIFIED BY \'choose-a-long-password\';', $quote($user), $quote($host))];
        $tables = Mage::getSingleton('ysrtech_adminassistant/report_guard')->allowedTables();
        $exists = array_map('strtolower', Mage::getSingleton('core/resource')->getConnection('core_read')->listTables());

        foreach ($tables as $table) {
            if (in_array($table, $exists, true)) {
                $lines[] = sprintf('GRANT SELECT ON `%s`.`%s` TO %s@%s;', $dbname, $table, $quote($user), $quote($host));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * For the Check button: connect, read the grants, read one row.
     *
     * @return array{ok:bool, lines:array<int, array{level:string, text:string}>}
     */
    public function check(): array
    {
        $lines = [];

        try {
            $grants = $this->inspectGrants();
        } catch (Mage_Core_Exception $e) {
            return ['ok' => false, 'lines' => [['level' => 'error', 'text' => $e->getMessage()]]];
        } catch (Throwable $e) {
            return ['ok' => false, 'lines' => [['level' => 'error', 'text' => 'Could not connect: ' . mb_substr($e->getMessage(), 0, 200)]]];
        }

        if (!$grants['read_only']) {
            return ['ok' => false, 'lines' => [['level' => 'error', 'text' => 'This user can change data, so the assistant will not use it: ' . implode(', ', $grants['unsafe'])]]];
        }

        $lines[] = ['level' => 'ok', 'text' => 'Connected. The user can read and nothing more.'];

        if ($grants['whole_database']) {
            $lines[] = ['level' => 'warn', 'text' => 'The user can read every table in the database. The assistant still keeps to its allowed list, but granting SELECT table by table (the statements above) puts the database itself in charge of that too.'];
        }

        // Every chosen table, read as this user: a table chosen after the user
        // was created needs its own GRANT
        $tables     = Mage::getSingleton('ysrtech_adminassistant/report_guard')->allowedTables();
        $unreadable = [];
        foreach ($tables as $table) {
            try {
                $this->connection()->fetchOne('SELECT 1 FROM `' . str_replace('`', '', $table) . '` LIMIT 0');
            } catch (Throwable $e) {
                $unreadable[] = $table;
            }
        }

        if (!$tables) {
            $lines[] = ['level' => 'warn', 'text' => 'No tables are chosen, so the report query can read nothing.'];
        } elseif ($unreadable) {
            $lines[] = ['level' => 'warn', 'text' => sprintf('%d of the %d chosen tables cannot be read by this user yet: %s. Run the statements above again as a database administrator.', count($unreadable), count($tables), implode(', ', array_slice($unreadable, 0, 12)) . (count($unreadable) > 12 ? ', …' : ''))];
        } else {
            $lines[] = ['level' => 'ok', 'text' => sprintf('It can read all %d chosen tables.', count($tables))];
        }

        try {
            $this->run('SELECT COUNT(*) AS orders FROM ' . Mage::getSingleton('core/resource')->getTableName('sales/order'));
            $lines[] = ['level' => 'ok', 'text' => 'A test query on the orders table ran.'];
        } catch (Mage_Core_Exception $e) {
            $lines[] = ['level' => 'error', 'text' => $e->getMessage()];

            return ['ok' => false, 'lines' => $lines];
        }

        return ['ok' => true, 'lines' => $lines];
    }
}

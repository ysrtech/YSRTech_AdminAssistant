<?php
/**
 * Decides whether a query the model wrote may run, and rewrites it to stay
 * under the row cap.
 *
 * This is one of three locks, and not the main one: the query runs as a
 * database user that can only SELECT (checked before every run), inside a
 * read-only transaction with a statement time limit. This check is what keeps
 * the query to the allowed tables when that user was granted more than it
 * should have been, and it refuses anything that is not a plain SELECT.
 *
 * It works on tokens, not on a full SQL grammar, and errs towards refusing:
 * a query it cannot read with certainty is refused with a reason the model
 * can act on.
 */
class YSRTech_AdminAssistant_Model_Report_Guard
{
    public const MAX_LENGTH = 6000;

    /**
     * Tables the report query may read, by model entity, so a table prefix
     * is applied. Nothing holding payment details, password hashes, API keys,
     * settings or admin users is here: not sales_flat_order_payment,
     * sales_flat_quote_payment, core_config_data, admin_*, api_*, oauth_*,
     * customer_entity_varchar (which holds password hashes and reset tokens).
     */
    public const CORE_TABLES = [
        'sales/order', 'sales/order_item', 'sales/order_address', 'sales/order_status_history', 'sales/order_grid',
        'sales/order_status', 'sales/order_status_state',
        'sales/invoice', 'sales/invoice_item', 'sales/shipment', 'sales/shipment_item', 'sales/shipment_track',
        'sales/creditmemo', 'sales/creditmemo_item',
        'sales/quote', 'sales/quote_item', 'sales/quote_address',
        'salesrule/rule', 'salesrule/coupon',
        'customer/entity', 'customer/customer_group', 'customer/address_entity',
        'catalog/product', 'catalog/category', 'catalog/category_product', 'catalog/product_website',
        'catalog/product_super_link', 'catalog/product_relation', 'catalog/product_index_price',
        'cataloginventory/stock_item', 'cataloginventory/stock_status',
        'eav/attribute', 'eav/entity_type', 'eav/attribute_option', 'eav/attribute_option_value', 'eav/attribute_set',
        'newsletter/subscriber',
        'core/store', 'core/website', 'core/store_group',
        'review/review', 'review/review_detail',
        'tax/tax_class',
        'directory/country',
    ];

    /** Value tables named by suffix: catalog_product_entity_varchar and so on */
    public const EAV_VALUE_TABLES = [
        'catalog_product_entity' => ['varchar', 'int', 'decimal', 'datetime', 'text'],
        'catalog_category_entity' => ['varchar', 'int', 'decimal', 'datetime', 'text'],
        'customer_address_entity' => ['varchar', 'int', 'text', 'datetime', 'decimal'],
    ];

    /** Email Campaigns' tables, when it is installed */
    public const EMAILCAMPAIGNS_TABLES = [
        'ysrtech_emailcampaigns/campaign', 'ysrtech_emailcampaigns/queue', 'ysrtech_emailcampaigns/segment',
        'ysrtech_emailcampaigns/segment_subscriber', 'ysrtech_emailcampaigns/campaign_segment',
        'ysrtech_emailcampaigns/conversion', 'ysrtech_emailcampaigns/link_click', 'ysrtech_emailcampaigns/suppression',
        'ysrtech_emailcampaigns/automation', 'ysrtech_emailcampaigns/automation_step',
    ];

    /**
     * Columns refused wherever they appear: secrets that live in an otherwise
     * allowed table, and tokens that act as passwords (unsubscribe and
     * guest-order links).
     */
    public const DENIED_COLUMNS = [
        'password_hash', 'rp_token', 'rp_token_created_at', 'subscriber_confirm_code', 'tracking_token',
        'lock_token', 'protect_code', 'additional_information', 'additional_data', 'cc_number_enc', 'cc_last4',
        'cc_exp_month', 'cc_exp_year', 'cc_owner', 'cc_type', 'echeck_account_name', 'echeck_bank_name',
        'po_number', 'conditions_serialized', 'actions_serialized', 'html', 'design_json', 'letter_text',
    ];

    /** Words that may not appear outside a string, whatever their position */
    public const FORBIDDEN_WORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'MERGE', 'DROP', 'ALTER', 'CREATE', 'TRUNCATE', 'RENAME', 'GRANT', 'REVOKE',
        'CALL', 'EXECUTE', 'EXEC', 'PREPARE', 'DEALLOCATE', 'DO', 'HANDLER', 'LOAD', 'LOCK', 'UNLOCK', 'SET',
        'SHOW', 'DESCRIBE', 'EXPLAIN', 'ANALYZE', 'OPTIMIZE', 'REPAIR', 'USE', 'KILL', 'SHUTDOWN',
        'FLUSH', 'RESET', 'PURGE', 'INSTALL', 'UNINSTALL', 'INTO', 'OUTFILE', 'DUMPFILE', 'LOAD_FILE', 'SLEEP',
        'BENCHMARK', 'GET_LOCK', 'RELEASE_LOCK', 'RELEASE_ALL_LOCKS', 'IS_FREE_LOCK', 'IS_USED_LOCK',
        'MASTER_POS_WAIT', 'MASTER_GTID_WAIT', 'SHARE', 'PROCEDURE', 'DATABASE', 'SCHEMA', 'USER', 'CURRENT_USER',
        'SESSION_USER', 'SYSTEM_USER', 'CURRENT_ROLE', 'PASSWORD', 'OLD_PASSWORD', 'ENCRYPT', 'DECODE',
        'DES_DECRYPT', 'AES_DECRYPT', 'LAST_INSERT_ID', 'ROW_COUNT', 'FOUND_ROWS', 'CONNECTION_ID',
        'SQL_CALC_FOUND_ROWS', 'HIGH_PRIORITY', 'NEXTVAL', 'SETVAL', 'LASTVAL', 'WITH', 'RECURSIVE',
        'INFORMATION_SCHEMA', 'PERFORMANCE_SCHEMA', 'MYSQL', 'SYS', 'VALUES', 'TABLE', 'NEXT', 'WINDOW', 'JSON_TABLE',
    ];

    /** Where a list of tables ends */
    private const TABLE_LIST_END = [
        'WHERE', 'GROUP', 'ORDER', 'HAVING', 'LIMIT', 'UNION', 'JOIN', 'INNER', 'LEFT', 'RIGHT', 'CROSS', 'OUTER',
        'NATURAL', 'STRAIGHT_JOIN', 'ON', 'USING', 'FULL', 'EXCEPT', 'INTERSECT', 'FOR', 'PARTITION',
    ];

    /** Functions whose own syntax uses FROM */
    private const FROM_FUNCTIONS = ['EXTRACT', 'TRIM', 'SUBSTRING', 'SUBSTR', 'POSITION', 'OVERLAY', 'MID'];

    /** @var array<string, true>|null */
    private ?array $_allowed = null;

    /** @var array<string, true>|null Every table in the database, allowed or not */
    private ?array $_existing = null;

    /**
     * @param array{allowed?:string[], existing?:string[]}|null $tables Exact table names, for tests; null reads them from this store
     */
    public function __construct($tables = null)
    {
        // Mage::getModel() passes an empty array; only a test passes these keys
        if (is_array($tables) && isset($tables['allowed'])) {
            $this->_allowed  = array_fill_keys(array_map('strtolower', $tables['allowed']), true);
            $this->_existing = array_fill_keys(array_map('strtolower', $tables['existing'] ?? []), true);
        }
    }

    /**
     * Every table in the store's database. Used as a catch-all: a word in the
     * query that names any table not on the allowed list is refused wherever
     * it stands, so a table reference the parser below did not recognise as
     * one is still caught.
     *
     * @return array<string, true>
     */
    private function _existingTables(): array
    {
        if ($this->_existing === null) {
            $this->_existing = array_fill_keys(
                array_map('strtolower', Mage::getSingleton('core/resource')->getConnection('core_read')->listTables()),
                true
            );
        }

        return $this->_existing;
    }

    /**
     * @return string[] The real table names allowed in this store, prefix included
     */
    /**
     * Tables that can never be read, whatever is chosen in the settings:
     * matched against the name without the store's table prefix. Payment
     * records, admin users, API and OAuth credentials, saved settings (where
     * keys live), sessions, and the customer value tables that hold password
     * hashes and reset tokens. Also this module's own chats and audit log,
     * which hold other admins' questions.
     */
    public const FORBIDDEN_TABLES = [
        '/^admin_/', '/^api_/', '/^api2_/', '/^oauth_/', '/^core_config_data$/', '/^core_session$/', '/session/',
        '/^persistent_/', '/^customer_entity_(varchar|text)$/', '/payment/', '/^sales_payment_transaction$/',
        '/^paypal_/', '/^authorizenet/', '/^captcha_log$/', '/password/', '/token/', '/secret/', '/credential/',
        '/^core_encryption/', '/billing_agreement/', '/recurring_profile/', '/^ysrtech_adminassistant_/', '/^ysrtech_emailcampaigns_webhook_event$/', '/^customer_flowpassword$/',
    ];

    /**
     * Whether a table may never be read. Takes the real name, prefix included.
     */
    public static function isForbiddenTable(string $name): bool
    {
        $prefix = strtolower((string) Mage::getConfig()->getTablePrefix());
        $bare   = strtolower($name);

        if ($prefix !== '' && strpos($bare, $prefix) === 0) {
            $bare = substr($bare, strlen($prefix));
        }

        foreach (self::FORBIDDEN_TABLES as $pattern) {
            if (preg_match($pattern, $bare)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The tables chosen for the report query: the recommended list, or the
     * owner's own choice from the settings. Forbidden tables are left out
     * either way, and so is anything that does not exist.
     *
     * @return string[] Real table names, prefix included
     */
    public function allowedTables(): array
    {
        if ($this->_allowed === null) {
            $h      = Mage::helper('ysrtech_adminassistant');
            $names  = $this->recommendedTables();

            if ($h->getConfig('report/table_mode') === 'custom') {
                $exists = array_fill_keys(array_map('strtolower', Mage::getSingleton('core/resource')->getConnection('core_read')->listTables()), true);
                $names  = array_filter(
                    array_map('trim', explode(',', strtolower((string) $h->getConfig('report/tables')))),
                    static fn ($t) => $t !== '' && isset($exists[$t])
                );
            }

            $names = array_filter($names, static fn ($t) => !self::isForbiddenTable($t));
            $this->_allowed = array_fill_keys(array_map('strtolower', $names), true);
        }

        return array_keys($this->_allowed);
    }

    /**
     * The tables offered by default: enough to answer questions about orders,
     * customers, the catalogue and campaigns, and nothing more.
     *
     * @return string[]
     */
    public function recommendedTables(): array
    {
        $resource = Mage::getSingleton('core/resource');
        $names    = [];

        foreach (self::CORE_TABLES as $entity) {
            $names[] = $resource->getTableName($entity);
        }

        foreach (self::EAV_VALUE_TABLES as $base => $types) {
            foreach ($types as $type) {
                $names[] = $resource->getTableName($base) . '_' . $type;
            }
        }

        if (Mage::helper('core')->isModuleEnabled('YSRTech_EmailCampaigns')) {
            foreach (self::EMAILCAMPAIGNS_TABLES as $entity) {
                $names[] = $resource->getTableName($entity);
            }
        }

        return array_values(array_unique(array_map('strtolower', $names)));
    }

    public function isAllowedTable(string $name): bool
    {
        $this->allowedTables();

        return isset($this->_allowed[strtolower($name)]);
    }

    /**
     * Column names that read as secrets, refused in any table: a table the
     * owner chose from another module can hold keys the list above does not
     * name.
     */
    public const DENIED_COLUMN_PATTERN = '/(password|passwd|secret|api_?key|private_?key|_token$|^token$|_hash$|^hash$|cc_cid|cc_number|card_number|cvv)/i';

    public static function isDeniedColumn(string $name): bool
    {
        return in_array(strtolower($name), self::DENIED_COLUMNS, true) || (bool) preg_match(self::DENIED_COLUMN_PATTERN, $name);
    }

    /**
     * Check a query and return it ready to run: the same query with its row
     * count capped at $cap + 1 (one more than is shown, to tell the model it
     * was cut).
     *
     * @return array{sql:string, tables:string[]}
     * @throws Mage_Core_Exception With the reason, for the model
     */
    public function prepare(string $sql, int $cap): array
    {
        $sql = trim($sql);

        if ($sql === '') {
            self::refuse('The query is empty.');
        }

        if (strlen($sql) > self::MAX_LENGTH) {
            self::refuse('The query is longer than ' . self::MAX_LENGTH . ' characters.');
        }

        $tokens = $this->tokenize($sql);

        // One trailing semicolon is forgiven; any other ends the check
        if ($tokens && end($tokens)['t'] === 'p' && end($tokens)['v'] === ';') {
            array_pop($tokens);
        }

        if (!$tokens || $tokens[0]['t'] !== 'w' || strtoupper($tokens[0]['v']) !== 'SELECT') {
            self::refuse('Only a single SELECT statement may run (WITH, SHOW, DESCRIBE and the like are not allowed; use subqueries).');
        }

        $tables = [];
        $stack  = [];   // per open parenthesis: 'query', 'derived', 'from_fn' or 'expr'
        $count  = count($tokens);
        $derivedAt     = -1; // the index of a '(' that opens a derived table
        $derivedSingle = []; // per depth: whether that derived table came after JOIN (no comma list)

        for ($i = 0; $i < $count; $i++) {
            $tok   = $tokens[$i];
            $upper = strtoupper($tok['v']);

            if ($tok['t'] === 'p') {
                if ($tok['v'] === ';') {
                    self::refuse('Only one statement may run.');
                }

                if ($tok['v'] === '(') {
                    $prev = $tokens[$i - 1] ?? null;
                    $next = $tokens[$i + 1] ?? null;
                    if ($i === $derivedAt) {
                        if (!$next || $next['t'] !== 'w' || strtoupper($next['v']) !== 'SELECT') {
                            self::refuse('A table name or a (SELECT ...) was expected after FROM or JOIN.');
                        }
                        $stack[] = 'derived';
                    } elseif ($next && $next['t'] === 'w' && strtoupper($next['v']) === 'SELECT') {
                        $stack[] = 'query';
                    } elseif ($prev && $prev['t'] === 'w' && in_array(strtoupper($prev['v']), self::FROM_FUNCTIONS, true)) {
                        $stack[] = 'from_fn';
                    } else {
                        $stack[] = 'expr';
                    }
                } elseif ($tok['v'] === ')') {
                    if (!$stack) {
                        self::refuse('The parentheses do not balance.');
                    }
                    if (array_pop($stack) === 'derived') {
                        // The derived table's alias, then more of the same table list
                        $j = $i + 1;
                        if (isset($tokens[$j]) && $tokens[$j]['t'] === 'w' && strtoupper($tokens[$j]['v']) === 'AS') {
                            $j++;
                        }
                        if (isset($tokens[$j]) && ($tokens[$j]['t'] === 'q' || ($tokens[$j]['t'] === 'w' && !in_array(strtoupper($tokens[$j]['v']), self::TABLE_LIST_END, true)))) {
                            if ($tokens[$j]['t'] === 'w' && in_array(strtoupper($tokens[$j]['v']), self::FORBIDDEN_WORDS, true)) {
                                self::refuse('"' . $tokens[$j]['v'] . '" is not allowed in a report query.');
                            }
                            $j++;
                        }
                        if (isset($tokens[$j]) && $tokens[$j]['t'] === 'p' && $tokens[$j]['v'] === ',' && $derivedSingle[count($stack)] === false) {
                            $i = $this->_tableList($tokens, $j + 1, false, $tables);
                            $derivedAt = ($tokens[$i]['v'] ?? '') === '(' ? $i : -1;
                            $derivedSingle[count($stack)] = false;
                            $i--;
                        }
                    }
                }

                continue;
            }

            if ($tok['t'] === 'w' || $tok['t'] === 'q') {
                if ($tok['t'] === 'w' && in_array($upper, self::FORBIDDEN_WORDS, true)) {
                    // REPLACE() and similar are functions; the statement forms
                    // cannot appear after SELECT anyway. These words never can.
                    self::refuse('"' . $tok['v'] . '" is not allowed in a report query.');
                }

                if (self::isDeniedColumn($tok['v'])) {
                    self::refuse('The column ' . $tok['v'] . ' holds a secret and cannot be read.');
                }

                if (isset($this->_existingTables()[strtolower($tok['v'])]) && !$this->isAllowedTable($tok['v'])) {
                    self::refuse('The table ' . $tok['v'] . ' is not on the allowed list. Use report_schema to see the tables that are.');
                }
            }

            if ($tok['t'] === 'w' && ($upper === 'FROM' || $upper === 'JOIN' || $upper === 'STRAIGHT_JOIN')) {
                if ($upper === 'FROM' && end($stack) === 'from_fn') {
                    continue; // EXTRACT(YEAR FROM created_at)
                }

                $i = $this->_tableList($tokens, $i + 1, $upper !== 'FROM', $tables);
                if (($tokens[$i]['v'] ?? '') === '(' && ($tokens[$i]['t'] ?? '') === 'p') {
                    $derivedAt = $i;
                    $derivedSingle[count($stack)] = $upper !== 'FROM';
                }
                $i--; // the loop's own step lands on the token that ended the list
            }
        }

        if ($stack) {
            self::refuse('The parentheses do not balance.');
        }

        if (!$tables) {
            self::refuse('The query reads no table.');
        }

        return ['sql' => $this->_capped($tokens, $cap + 1), 'tables' => array_values(array_unique($tables))];
    }

    /**
     * Read the table references after FROM or JOIN; return the index of the
     * first token after them.
     */
    private function _tableList(array $tokens, int $i, bool $single, array &$tables): int
    {
        $count = count($tokens);

        while ($i < $count) {
            $tok = $tokens[$i];

            if ($tok['t'] === 'p' && $tok['v'] === '(') {
                return $i; // a derived table: its own FROM is checked as the scan reaches it
            }

            if ($tok['t'] !== 'w' && $tok['t'] !== 'q') {
                self::refuse('A table name was expected after FROM or JOIN.');
            }

            $name = $tok['v'];

            if (($tokens[$i + 1]['v'] ?? '') === '.') {
                self::refuse('Tables must be named without a database (' . $name . '.' . ($tokens[$i + 2]['v'] ?? '') . ').');
            }

            if ($tok['t'] === 'w' && strtoupper($name) === 'DUAL') {
                $i++;
            } else {
                if (!$this->isAllowedTable($name)) {
                    self::refuse('The table ' . $name . ' is not on the allowed list. Use report_schema to see the tables that are.');
                }
                $tables[] = strtolower($name);
                $i++;
            }

            // Optional alias, with or without AS
            if (isset($tokens[$i]) && $tokens[$i]['t'] === 'w' && strtoupper($tokens[$i]['v']) === 'AS') {
                $i++;
            }

            if (isset($tokens[$i]) && ($tokens[$i]['t'] === 'q' || ($tokens[$i]['t'] === 'w' && !in_array(strtoupper($tokens[$i]['v']), self::TABLE_LIST_END, true)))) {
                // Words read here skip the main scan, so they get its checks
                if ($tokens[$i]['t'] === 'w' && in_array(strtoupper($tokens[$i]['v']), self::FORBIDDEN_WORDS, true)) {
                    self::refuse('"' . $tokens[$i]['v'] . '" is not allowed in a report query.');
                }
                $i++;
            }

            // Index hints are refused: FORCE INDEX and friends only tune a plan
            if (isset($tokens[$i]) && $tokens[$i]['t'] === 'w' && in_array(strtoupper($tokens[$i]['v']), ['USE', 'FORCE', 'IGNORE'], true)) {
                self::refuse('Index hints are not allowed.');
            }

            if (!$single && isset($tokens[$i]) && $tokens[$i]['t'] === 'p' && $tokens[$i]['v'] === ',') {
                $i++;
                continue;
            }

            return $i;
        }

        return $i;
    }

    /**
     * Rebuild the query with its outer LIMIT at most $max rows.
     */
    private function _capped(array $tokens, int $max): string
    {
        $depth = 0;
        $limit = null;

        foreach ($tokens as $i => $tok) {
            if ($tok['t'] === 'p' && $tok['v'] === '(') {
                $depth++;
            } elseif ($tok['t'] === 'p' && $tok['v'] === ')') {
                $depth--;
            } elseif ($depth === 0 && $tok['t'] === 'w' && strtoupper($tok['v']) === 'LIMIT') {
                $limit = $i;
            }
        }

        if ($limit !== null) {
            // LIMIT n | LIMIT offset, n | LIMIT n OFFSET offset
            $a = $tokens[$limit + 1] ?? null;
            $b = $tokens[$limit + 2] ?? null;
            $c = $tokens[$limit + 3] ?? null;

            if (!$a || $a['t'] !== 'n') {
                self::refuse('LIMIT takes plain numbers.');
            }

            $rowsAt = ($b && $b['v'] === ',' && $c && $c['t'] === 'n') ? $limit + 3 : $limit + 1;
            $rest   = array_slice($tokens, $limit + 1);

            foreach ($rest as $t) {
                if ($t['t'] !== 'n' && !in_array(strtoupper($t['v']), [',', 'OFFSET'], true)) {
                    self::refuse('Nothing may follow LIMIT but its numbers.');
                }
            }

            $tokens[$rowsAt]['v'] = (string) min((int) $tokens[$rowsAt]['v'], $max);
        } else {
            $tokens[] = ['sp' => true, 't' => 'w', 'v' => 'LIMIT'];
            $tokens[] = ['sp' => true, 't' => 'n', 'v' => (string) $max];
        }

        $out = '';

        foreach ($tokens as $tok) {
            $text = $tok['t'] === 'q' ? '`' . str_replace('`', '``', $tok['v']) . '`' : $tok['v'];
            // Whitespace only where the query had it: "COUNT (*)" is not "COUNT(*)" to MySQL
            $out .= ($out !== '' && !empty($tok['sp']) ? ' ' : '') . $text;
        }

        return $out;
    }

    /**
     * Split a query into words (w), quoted identifiers (q, unquoted value),
     * strings (s, as written), numbers (n) and punctuation (p). Comments,
     * variables and anything unrecognised are refused.
     *
     * @return array<int, array{t:string, v:string}>
     */
    public function tokenize(string $sql): array
    {
        $tokens = [];
        $len    = strlen($sql);
        $i      = 0;

        while ($i < $len) {
            $c = $sql[$i];

            if (ctype_space($c)) {
                $i++;
                continue;
            }

            $space  = $i > 0 && ctype_space($sql[$i - 1]);

            $two = substr($sql, $i, 2);

            if ($c === '#' || $two === '--' || $two === '/*' || $two === '*/') {
                self::refuse('Comments are not allowed in a report query.');
            }

            if ($c === '"') {
                self::refuse('Write strings in single quotes.');
            }

            if ($c === "'") {
                $j = $i + 1;
                while ($j < $len) {
                    // A backslash means different things under different SQL
                    // modes, so a string holding one could end in a different
                    // place for the server than for this check
                    if ($sql[$j] === '\\') {
                        self::refuse('Backslashes are not allowed in strings.');
                    }
                    if ($sql[$j] === $c) {
                        if (($sql[$j + 1] ?? '') === $c) {
                            $j += 2;
                            continue;
                        }
                        break;
                    }
                    $j++;
                }
                if ($j >= $len) {
                    self::refuse('A string is not closed.');
                }
                $tokens[] = ['sp' => $space, 't' => 's', 'v' => substr($sql, $i, $j - $i + 1)];
                $i        = $j + 1;
                continue;
            }

            if ($c === '`') {
                $j = strpos($sql, '`', $i + 1);
                if ($j === false) {
                    self::refuse('A quoted name is not closed.');
                }
                $name = substr($sql, $i + 1, $j - $i - 1);
                if (!preg_match('/^[A-Za-z0-9_$]+$/', $name)) {
                    self::refuse('Quoted names may only hold letters, digits and underscores.');
                }
                $tokens[] = ['sp' => $space, 't' => 'q', 'v' => $name];
                $i        = $j + 1;
                continue;
            }

            if (ctype_digit($c) || ($c === '.' && ctype_digit($sql[$i + 1] ?? ''))) {
                preg_match('/\G(?:0x[0-9A-Fa-f]+|\d*\.?\d+(?:[eE][+-]?\d+)?)/', $sql, $m, 0, $i);
                $tokens[] = ['sp' => $space, 't' => 'n', 'v' => $m[0]];
                $i       += strlen($m[0]);
                continue;
            }

            if (ctype_alpha($c) || $c === '_') {
                preg_match('/\G[A-Za-z0-9_$]+/', $sql, $m, 0, $i);
                $tokens[] = ['sp' => $space, 't' => 'w', 'v' => $m[0]];
                $i       += strlen($m[0]);
                continue;
            }

            foreach (['<=>', '>=', '<=', '<>', '!=', '||', '&&', '<<', '>>'] as $op) {
                if (substr($sql, $i, strlen($op)) === $op) {
                    $tokens[] = ['sp' => $space, 't' => 'p', 'v' => $op];
                    $i       += strlen($op);
                    continue 2;
                }
            }

            if (strpos('(),.;*+-/%=<>!&|^~', $c) !== false) {
                $tokens[] = ['sp' => $space, 't' => 'p', 'v' => $c];
                $i++;
                continue;
            }

            if ($c === '@') {
                self::refuse('Variables are not allowed in a report query.');
            }

            self::refuse('The character "' . $c . '" is not allowed in a report query.');
        }

        return $tokens;
    }

    /**
     * @return never
     */
    public static function refuse(string $why): void
    {
        throw new Mage_Core_Exception('Query refused: ' . $why);
    }
}

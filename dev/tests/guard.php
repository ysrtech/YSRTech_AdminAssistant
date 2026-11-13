<?php
/**
 * The report query guard, on its own: what it lets through, what it refuses,
 * and how it caps rows. Needs no database.
 *
 *   php <module>/dev/tests/guard.php      (from the OpenMage root)
 */
require 'app/Mage.php';
Mage::app('admin');

$pass = $fail = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail) {
    $ok ? $pass++ : $fail++;
    echo ($ok ? "  ok   " : "  FAIL ") . $name . ($ok || $detail === '' ? '' : "\n         " . $detail) . "\n";
};

$guard = new YSRTech_AdminAssistant_Model_Report_Guard([
    'allowed'  => ['sales_flat_order', 'sales_flat_order_item', 'customer_entity', 'catalog_product_entity', 'catalog_product_entity_varchar', 'newsletter_subscriber', 'sales_flat_quote'],
    'existing' => ['sales_flat_order', 'sales_flat_order_item', 'customer_entity', 'catalog_product_entity', 'catalog_product_entity_varchar', 'newsletter_subscriber', 'sales_flat_quote',
                   'admin_user', 'core_config_data', 'sales_flat_order_payment', 'customer_entity_varchar', 'api_user', 'oauth_token'],
]);

$allows = static function (string $sql, ?string $expect = null) use ($guard, $check) {
    try {
        $out = $guard->prepare($sql, 200);
        $check('allows: ' . $sql, $expect === null || $out['sql'] === $expect, 'became: ' . $out['sql']);
    } catch (Mage_Core_Exception $e) {
        $check('allows: ' . $sql, false, $e->getMessage());
    }
};

$refuses = static function (string $sql, string $why = '') use ($guard, $check) {
    try {
        $out = $guard->prepare($sql, 200);
        $check('refuses: ' . $sql, false, 'let through as: ' . $out['sql']);
    } catch (Mage_Core_Exception $e) {
        $check('refuses: ' . $sql, $why === '' || stripos($e->getMessage(), $why) !== false, $e->getMessage());
    }
};

echo "Ordinary report queries\n";
$allows('SELECT COUNT(*) FROM sales_flat_order', 'SELECT COUNT(*) FROM sales_flat_order LIMIT 201');
$allows("SELECT status, COUNT(*) AS n, SUM(base_grand_total) FROM sales_flat_order WHERE created_at >= '2026-01-01' GROUP BY status ORDER BY n DESC");
$allows('SELECT o.increment_id, i.sku FROM sales_flat_order o JOIN sales_flat_order_item i ON i.order_id = o.entity_id WHERE o.state <> \'canceled\' LIMIT 10',
    'SELECT o.increment_id, i.sku FROM sales_flat_order o JOIN sales_flat_order_item i ON i.order_id = o.entity_id WHERE o.state <> \'canceled\' LIMIT 10');
$allows('SELECT * FROM sales_flat_order LIMIT 5000', 'SELECT * FROM sales_flat_order LIMIT 201');
$allows('SELECT * FROM sales_flat_order LIMIT 10, 5000', 'SELECT * FROM sales_flat_order LIMIT 10, 201');
$allows('SELECT * FROM sales_flat_order LIMIT 5000 OFFSET 20', 'SELECT * FROM sales_flat_order LIMIT 201 OFFSET 20');
$allows('SELECT YEAR(created_at) y, EXTRACT(MONTH FROM created_at) m, COUNT(*) FROM sales_flat_order GROUP BY y, m');
$allows("SELECT TRIM(LEADING '0' FROM increment_id) FROM sales_flat_order");
$allows('SELECT x.n FROM (SELECT COUNT(*) n FROM sales_flat_order) x');
$allows('SELECT a.n, b.m FROM (SELECT COUNT(*) n FROM sales_flat_order) a, (SELECT COUNT(*) m FROM customer_entity) b');
$allows('SELECT entity_id FROM customer_entity WHERE entity_id IN (SELECT customer_id FROM sales_flat_order WHERE base_grand_total > 100)');
$allows('SELECT `sku` FROM `catalog_product_entity`;');
$allows('SELECT increment_id FROM sales_flat_order UNION SELECT email FROM customer_entity');
$allows("SELECT customer_email FROM sales_flat_order WHERE customer_email LIKE '%o''brien%'");
$allows('SELECT RANK() OVER (ORDER BY base_grand_total DESC) r, increment_id FROM sales_flat_order');

echo "\nTables that are not allowed, however they are reached\n";
$refuses('SELECT * FROM admin_user', 'admin_user');
$refuses('SELECT * FROM `admin_user`', 'admin_user');
$refuses('SELECT * FROM sales_flat_order o JOIN sales_flat_order_payment p ON p.parent_id = o.entity_id', 'sales_flat_order_payment');
$refuses('SELECT * FROM sales_flat_order, core_config_data', 'core_config_data');
$refuses('SELECT * FROM (SELECT 1 n FROM sales_flat_order) a, admin_user b', 'admin_user');
$refuses('SELECT * FROM (SELECT 1 n FROM sales_flat_order) a, otherdb.wp_users b', 'database');
$refuses('SELECT (SELECT password FROM admin_user LIMIT 1) FROM sales_flat_order', '');
$refuses('SELECT * FROM sales_flat_order WHERE EXISTS (SELECT 1 FROM api_user)', 'api_user');
$refuses('SELECT value FROM customer_entity_varchar', 'customer_entity_varchar');
$refuses('SELECT * FROM mysql.user', 'mysql');
$refuses('SELECT * FROM information_schema.tables', 'information_schema');
$refuses('SELECT table_name FROM information_schema.columns', 'information_schema');
$refuses('SELECT * FROM otherdb.sales_flat_order', 'database');
$refuses('SELECT * FROM sales_flat_order AS o, oauth_token', 'oauth_token');
$refuses('SELECT 1', 'no table');

echo "\nSecret columns\n";
$refuses('SELECT password_hash FROM sales_flat_quote', 'password_hash');
$refuses('SELECT `password_hash` AS x FROM sales_flat_quote', 'password_hash');
$refuses('SELECT subscriber_confirm_code FROM newsletter_subscriber', 'subscriber_confirm_code');
$refuses('SELECT protect_code FROM sales_flat_order', 'protect_code');

echo "\nAnything that is not one plain SELECT\n";
$refuses('UPDATE sales_flat_order SET status = 1', 'SELECT');
$refuses('DELETE FROM sales_flat_order', 'SELECT');
$refuses('SELECT 1 FROM sales_flat_order; DROP TABLE sales_flat_order', 'one statement');
$refuses('SELECT * FROM sales_flat_order INTO OUTFILE \'/tmp/x\'', 'not allowed');
$refuses('SELECT LOAD_FILE(\'/etc/passwd\') FROM sales_flat_order', 'LOAD_FILE');
$refuses('SELECT SLEEP(10) FROM sales_flat_order', 'SLEEP');
$refuses('SELECT BENCHMARK(100000000, MD5(1)) FROM sales_flat_order', 'BENCHMARK');
$refuses('SELECT * FROM sales_flat_order FOR UPDATE', 'UPDATE');
$refuses('SELECT * FROM sales_flat_order LOCK IN SHARE MODE', 'not allowed');
$refuses('WITH x AS (SELECT * FROM admin_user) SELECT * FROM x', 'SELECT');
$refuses('SHOW TABLES', 'SELECT');
$refuses('SELECT @@version FROM sales_flat_order', 'Variables');
$refuses('SELECT @a := 1 FROM sales_flat_order', 'Variables');
$refuses('SELECT 1 FROM sales_flat_order -- x', 'Comments');
$refuses('SELECT 1 FROM sales_flat_order # x', 'Comments');
$refuses('SELECT 1 /*! FROM admin_user */ FROM sales_flat_order', 'Comments');
$refuses('SELECT USER() FROM sales_flat_order', 'USER');
$refuses('SELECT DATABASE() FROM sales_flat_order', 'DATABASE');
$refuses('SELECT * FROM sales_flat_order PROCEDURE ANALYSE()', 'PROCEDURE');
$refuses('SELECT * FROM sales_flat_order USE INDEX (PRIMARY)', '');
$refuses('SELECT * FROM sales_flat_order o USE INDEX (PRIMARY)', '');
$refuses('SELECT * FROM (SELECT * FROM sales_flat_order) PROCEDURE ANALYSE()', 'PROCEDURE');
$refuses('SELECT * FROM sales_flat_order INTO @x', '');
$refuses('SELECT * FROM sales_flat_order LIMIT 5 UNION SELECT * FROM admin_user', '');

echo "\nStrings that could end in a different place for the server\n";
$refuses("SELECT 'a\\' FROM admin_user -- ' FROM sales_flat_order", 'Backslash');
$refuses('SELECT "x" FROM sales_flat_order', 'single quotes');
$refuses('SELECT * FROM "admin_user"', 'single quotes');
$refuses("SELECT 'unterminated FROM sales_flat_order", 'not closed');
$refuses('SELECT * FROM `admin`user`', '');

echo "\nShape\n";
$refuses('SELECT (1 FROM sales_flat_order', 'parentheses');
$refuses('SELECT 1) FROM sales_flat_order', 'parentheses');
$refuses(str_repeat('SELECT 1 FROM sales_flat_order ', 300), 'longer');
$refuses('SELECT * FROM sales_flat_order LIMIT 5 + 5', 'LIMIT');

echo "\nThe real allowed list, from this store's config\n";
$real = new YSRTech_AdminAssistant_Model_Report_Guard();
$tables = $real->allowedTables();
$check('orders are allowed', in_array(Mage::getSingleton('core/resource')->getTableName('sales/order'), $tables, true));
foreach (['sales/order_payment', 'sales/quote_payment', 'core/config_data', 'admin/user', 'api/user', 'customer/entity'] as $entity) {
    $name = Mage::getSingleton('core/resource')->getTableName($entity);
    $check(($entity === 'customer/entity' ? 'allowed: ' : 'never allowed: ') . $name, in_array($name, $tables, true) === ($entity === 'customer/entity'));
}
$check('customer_entity_varchar is not allowed (password hashes live there)', !in_array(Mage::getSingleton('core/resource')->getTableName('customer/entity') . '_varchar', $tables, true));
try {
    $real->prepare('SELECT * FROM ' . Mage::getSingleton('core/resource')->getTableName('admin/user'), 10);
    $check('the real guard refuses admin_user', false);
} catch (Mage_Core_Exception $e) {
    $check('the real guard refuses admin_user', true);
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);

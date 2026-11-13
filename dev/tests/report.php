<?php
/**
 * The report query against a real database: a read-only user built from the
 * statements the settings page shows, a user that can write (refused), the
 * time limit, the row cap, and secret columns dropped from results.
 *
 * Creates two database users, so it needs the store's own database user to
 * be allowed to (the staging install's is). Restores the settings it changes.
 *
 *   php <module>/dev/tests/report.php      (from the OpenMage root)
 */
require 'app/Mage.php';
Mage::app('admin');

$pass = $fail = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail) {
    $ok ? $pass++ : $fail++;
    echo ($ok ? "  ok   " : "  FAIL ") . $name . ($ok || $detail === '' ? '' : "\n         " . $detail) . "\n";
};

$db     = Mage::getSingleton('core/resource')->getConnection('core_write');
$config = Mage::getModel('core/config');
$paths  = ['enabled', 'username', 'password', 'host', 'row_cap', 'time_limit', 'table_mode'];
$saved  = [];
foreach ($paths as $p) {
    $saved[$p] = Mage::getStoreConfig('ysrtech_adminassistant/report/' . $p, 0);
}

$set = static function (array $values) use ($config) {
    foreach ($values as $k => $v) {
        $config->saveConfig('ysrtech_adminassistant/report/' . $k, $v);
    }
    Mage::getConfig()->reinit();
    Mage::app()->reinitStores();
};

$fresh = static function () {
    // A new connection for each user under test
    Mage::unregister('_singleton/ysrtech_adminassistant/report_query');

    return Mage::getSingleton('ysrtech_adminassistant/report_query');
};

$ro = 'aa_test_ro';
$rw = 'aa_test_rw';
$pw = 'Test-' . bin2hex(random_bytes(6));

try {
    foreach ([$ro, $rw] as $u) {
        $db->query("DROP USER IF EXISTS '{$u}'@'localhost'");
    }
    $statements = Mage::getModel('ysrtech_adminassistant/report_query')->grantStatements($ro, 'localhost');
    foreach (explode("\n", str_replace('choose-a-long-password', $pw, $statements)) as $sql) {
        $db->query($sql);
    }
    $db->query("CREATE USER '{$rw}'@'localhost' IDENTIFIED BY '{$pw}'");
    $db->query('GRANT SELECT, INSERT ON `' . (string) Mage::getConfig()->getResourceConnectionConfig('default_setup')->dbname . "`.* TO '{$rw}'@'localhost'");
} catch (Throwable $e) {
    echo "Could not create test database users ({$e->getMessage()}); skipping.\n";
    exit(0);
}

echo "Grant statements\n";
$check('one GRANT per allowed table, SELECT only', substr_count($statements, 'GRANT SELECT ON') > 20 && stripos($statements, 'INSERT') === false);
$check('no payment, config or admin table in them', !preg_match('/sales_flat_order_payment|core_config_data|admin_user|api_user|customer_entity_varchar/', $statements));

echo "\nReading grants\n";
$r = YSRTech_AdminAssistant_Model_Report_Query::readGrants(["GRANT USAGE ON *.* TO `x`@`localhost`", "GRANT SELECT ON `db`.`sales_flat_order` TO `x`@`localhost`"]);
$check('SELECT and USAGE are read-only', $r['read_only'] && !$r['whole_database']);
$r = YSRTech_AdminAssistant_Model_Report_Query::readGrants(["GRANT SELECT ON `db`.* TO `x`@`localhost`"]);
$check('SELECT on a whole database is read-only, and noticed', $r['read_only'] && $r['whole_database']);
foreach (["GRANT ALL PRIVILEGES ON *.* TO `x`@`%`", "GRANT SELECT, UPDATE (status) ON `db`.`t` TO `x`@`%`", "GRANT SELECT ON `db`.* TO `x`@`%` WITH GRANT OPTION", "GRANT `admin_role` TO `x`@`%`", "GRANT FILE ON *.* TO `x`@`%`"] as $line) {
    $check('not read-only: ' . $line, !YSRTech_AdminAssistant_Model_Report_Query::readGrants([$line])['read_only']);
}

echo "\nA user that can write is refused\n";
$set(['enabled' => 1, 'username' => $rw, 'password' => Mage::helper('core')->encrypt($pw), 'host' => '', 'row_cap' => 50, 'time_limit' => 2]);
try {
    $fresh()->run('SELECT COUNT(*) FROM ' . Mage::getSingleton('core/resource')->getTableName('sales/order'));
    $check('refused', false, 'it ran');
} catch (Mage_Core_Exception $e) {
    $check('refused', stripos($e->getMessage(), 'can change data') !== false, $e->getMessage());
}
$check('the settings check says so too', !$fresh()->check()['ok']);

echo "\nThe store's own database user is refused\n";
$set(['username' => (string) Mage::getConfig()->getResourceConnectionConfig('default_setup')->username]);
try {
    $fresh()->run('SELECT 1 FROM ' . Mage::getSingleton('core/resource')->getTableName('sales/order'));
    $check('refused', false, 'it ran');
} catch (Mage_Core_Exception $e) {
    $check('refused', stripos($e->getMessage(), 'own database user') !== false, $e->getMessage());
}

echo "\nThe read-only user\n";
$set(['username' => $ro]);
$check = $check;
$result = $fresh()->check();
$check('the settings check passes', $result['ok'], json_encode($result['lines']));

$orders = Mage::getSingleton('core/resource')->getTableName('sales/order');
$quote  = Mage::getSingleton('core/resource')->getTableName('sales/quote');
$q      = $fresh();

$r = $q->run("SELECT state, COUNT(*) AS orders FROM {$orders} GROUP BY state ORDER BY orders DESC");
$check('a grouped count runs', count($r['rows']) > 1 && isset($r['rows'][0]['orders']), json_encode($r['rows']));

$r = $q->run("SELECT entity_id FROM {$orders}");
$check('rows are capped at the setting (50) and the cut is reported', count($r['rows']) === 50 && $r['truncated'] === true, count($r['rows']) . ' rows');

$r = $q->run("SELECT * FROM {$quote} LIMIT 3");
$check('SELECT * drops secret columns from the result', !in_array('password_hash', $r['columns'], true) && (in_array('password_hash', $r['dropped_columns'], true) || !$r['rows']), json_encode($r['dropped_columns']));

$r = $q->run("SELECT * FROM {$orders} LIMIT 1");
$check('protect_code is dropped from SELECT *', !isset($r['rows'][0]['protect_code']));

try {
    $q->run("SELECT COUNT(*) FROM {$orders} a, {$orders} b, {$orders} c, {$orders} d");
    $check('a query over the time limit is stopped', false, 'it finished');
} catch (Mage_Core_Exception $e) {
    $check('a query over the time limit is stopped', stripos($e->getMessage(), 'longer than') !== false, $e->getMessage());
}

try {
    $q->run("SELECT nosuchcolumn FROM {$orders}");
    $check('a database error comes back for the model to fix', false);
} catch (Mage_Core_Exception $e) {
    $check('a database error comes back for the model to fix', stripos($e->getMessage(), 'nosuchcolumn') !== false, $e->getMessage());
}

// Even if the guard were bypassed, the database itself refuses
$conn = $q->connection();
try {
    $conn->fetchAll('SELECT * FROM ' . Mage::getSingleton('core/resource')->getTableName('admin/user'));
    $check('the database refuses admin_user to this user, guard aside', false);
} catch (Throwable $e) {
    $check('the database refuses admin_user to this user, guard aside', true);
}
try {
    $conn->query("UPDATE {$orders} SET status = status WHERE 1 = 0");
    $check('the database refuses a write from this user, guard aside', false);
} catch (Throwable $e) {
    $check('the database refuses a write from this user, guard aside', true);
}

echo "\nChoosing tables\n";
$cms = Mage::getSingleton('core/resource')->getTableName('cms/page');
$backend = Mage::getModel('ysrtech_adminassistant/system_config_backend_tables')
    ->setPath('ysrtech_adminassistant/report/tables')->setScope('default')->setScopeId(0)
    ->setValue([$orders, $cms, 'admin_user', 'core_config_data', 'sales_flat_order_payment']);
$backend->save();

$set(['table_mode' => 'custom']);
Mage::unregister('_singleton/ysrtech_adminassistant/report_guard');
$check('saving a choice drops forbidden tables', Mage::getModel('core/config_data')->load('ysrtech_adminassistant/report/tables', 'path')->getValue() === $orders . ',' . $cms);
$guard = Mage::getSingleton('ysrtech_adminassistant/report_guard');
$check('the guard uses the choice', $guard->allowedTables() === [$orders, $cms], json_encode($guard->allowedTables()));
try {
    $guard->prepare('SELECT * FROM ' . Mage::getSingleton('core/resource')->getTableName('customer/entity'), 10);
    $check('a recommended table left out of the choice is refused', false);
} catch (Mage_Core_Exception $e) {
    $check('a recommended table left out of the choice is refused', stripos($e->getMessage(), 'not on the allowed list') !== false);
}
$result = $fresh()->check();
$text   = implode(' ', array_column($result['lines'], 'text'));
$check('the check names a chosen table the user cannot read yet', stripos($text, $cms) !== false && stripos($text, 'cannot be read') !== false, $text);
try {
    $fresh()->run("SELECT COUNT(*) FROM {$cms}");
    $check('and the database refuses it until it is granted', false);
} catch (Mage_Core_Exception $e) {
    $check('and the database refuses it until it is granted', stripos($e->getMessage(), 'refused') !== false, $e->getMessage());
}
$check('the GRANT statements follow the choice', strpos($fresh()->grantStatements($ro), $cms) !== false);
$db->query('GRANT SELECT ON `' . (string) Mage::getConfig()->getResourceConnectionConfig('default_setup')->dbname . "`.`{$cms}` TO '{$ro}'@'localhost'");
$r = $fresh()->run("SELECT COUNT(*) AS pages FROM {$cms}");
$check('once granted, the chosen table answers', isset($r['rows'][0]['pages']));
$set(['table_mode' => 'recommended']);
Mage::getModel('core/config')->deleteConfig('ysrtech_adminassistant/report/tables');
Mage::unregister('_singleton/ysrtech_adminassistant/report_guard');

echo "\nThrough the tool, with the audit log\n";
Mage::getSingleton('ysrtech_adminassistant/audit')->begin(null, null);
$tools = Mage::getModel('ysrtech_adminassistant/tools')->setAclCheck(static fn () => true);
$out   = $tools->call('report_query', json_encode(['sql' => "SELECT COUNT(*) AS n FROM {$orders}", 'purpose' => 'count orders']));
$check('report_query answers', $out['ok'] && strpos($out['content'], '"n"') !== false, $out['content']);
$out   = $tools->call('report_query', json_encode(['sql' => 'SELECT * FROM ' . Mage::getSingleton('core/resource')->getTableName('admin/user'), 'purpose' => 'try']));
$check('report_query refuses admin_user, with a reason', !$out['ok'] && stripos($out['content'], 'not on the allowed list') !== false, $out['content']);
$out   = $tools->call('report_schema', json_encode(['tables' => [$orders]]));
$check('report_schema lists columns, without protect_code', $out['ok'] && strpos($out['content'], 'base_grand_total') !== false && strpos($out['content'], 'protect_code') === false);

// ---- clean up
foreach ([$ro, $rw] as $u) {
    $db->query("DROP USER IF EXISTS '{$u}'@'localhost'");
}
foreach ($saved as $k => $v) {
    $v === null ? $config->deleteConfig('ysrtech_adminassistant/report/' . $k) : $config->saveConfig('ysrtech_adminassistant/report/' . $k, $v);
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);

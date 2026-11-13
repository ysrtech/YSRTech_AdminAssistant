<?php
/**
 * The admin wiki: the Markdown renderer (above all, that a page cannot run
 * script), saving with history and edit conflicts, delete and restore, how
 * starter pages are added and updated, search, and the assistant's wiki
 * look-ups.
 *
 *   php <module>/dev/tests/wiki.php      (from the OpenMage root)
 */
require 'app/Mage.php';
Mage::app('admin');

$pass = $fail = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail) {
    $ok ? $pass++ : $fail++;
    echo ($ok ? "  ok   " : "  FAIL ") . $name . ($ok || $detail === '' ? '' : "\n         " . mb_substr($detail, 0, 500)) . "\n";
};

$md = new YSRTech_AdminAssistant_Model_Wiki_Markdown([
    'page_title' => static fn ($k) => $k === 'credit-memos' ? 'Refunds' : null,
    'page_url'   => static fn ($k) => '/wiki/' . $k,
    'admin_url'  => static fn ($r) => '/admin/' . $r . '/key/abc/',
]);
$r = static fn (string $s) => $md->render($s);

echo "A page cannot run script\n";
$attacks = [
    '<script>alert(1)</script>',
    '<img src=x onerror=alert(1)>',
    '[click](javascript:alert(1))',
    '[click](JaVaScRiPt:alert(1))',
    '[click](data:text/html;base64,PHNjcmlwdD4=)',
    '![x](javascript:alert(1))',
    '![x" onerror="alert(1)](https://example.com/a.png)',
    '[a" onmouseover="alert(1)](https://example.com)',
    '[x](https://example.com" onclick="alert(1))',
    '[[x" onclick="alert(1)]]',
    '| <b onclick=alert(1)>h</b> |' . "\n" . '|---|' . "\n" . '| <script>x</script> |',
    "> [!TIP]\n> <iframe src=//evil></iframe>",
    '`<script>alert(1)</script>`',
    "```\n<script>alert(1)</script>\n```",
    '## <svg onload=alert(1)>',
    '[ok](admin:sales_order" onclick="x)',
    '**<img src=x onerror=alert(1)>**',
];
/** Elements, event attributes and script URLs that would run, found by parsing the HTML */
$dangers = static function (string $html): array {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>');
    libxml_clear_errors();
    $found = [];
    foreach ((new DOMXPath($doc))->query('//*') as $el) {
        if (in_array(strtolower($el->nodeName), ['script', 'iframe', 'svg', 'object', 'embed', 'style', 'form', 'input'], true)) {
            $found[] = '<' . $el->nodeName . '>';
        }
        foreach ($el->attributes ?? [] as $attr) {
            $name = strtolower($attr->name);
            if (strpos($name, 'on') === 0 || (in_array($name, ['href', 'src'], true) && preg_match('/^\s*(javascript|data|vbscript):/i', $attr->value))) {
                $found[] = $name . '=' . $attr->value;
            }
        }
    }

    return $found;
};
foreach ($attacks as $a) {
    $found = $dangers($r($a));
    $check('safe: ' . str_replace("\n", '⏎', $a), !$found, implode(' ', $found) . ' in ' . $r($a));
}

echo "\nFormatting\n";
$check('headings get anchors, and go into the table of contents', strpos($r("## Which button\n### Steps"), '<h2 id="which-button">') !== false && count($md->headings()) === 2);
$check('numbered steps', strpos($r("1. One\n2. Two"), '<ol><li>One</li><li>Two</li></ol>') !== false);
$check('nested list', strpos($r("- a\n  - b\n- c"), '<ul><li>a<ul><li>b</li></ul></li><li>c</li></ul>') !== false, $r("- a\n  - b\n- c"));
$check('bold, italic, code', $r('**B** *i* `c`') === '<p><strong>B</strong> <em>i</em> <code>c</code></p>', $r('**B** *i* `c`'));
$t = $r("| Write | Get |\n|---|---|\n| `[[a|b]]` | link |");
$check('a pipe inside code does not split a table cell', strpos($t, '<td><code>[[a|b]]</code></td><td>link</td>') !== false, $t);
$check('a callout', strpos($r("> [!WARNING]\n> Careful"), 'class="wk-callout wk-warning"') !== false);
$check('a wiki link uses the page title', strpos($r('[[credit-memos]]'), '<a class="wk-link" href="/wiki/credit-memos">Refunds</a>') !== false, $r('[[credit-memos]]'));
$check('a link to a page not written yet is marked', strpos($r('[[no-such-page|New]]'), 'wk-missing') !== false);
$check('an admin link carries the secret key', strpos($r('[Orders](admin:sales_order)'), 'href="/admin/sales_order/key/abc/"') !== false);
$check('an outside link opens in a new tab, without referrer', strpos($r('[x](https://example.com)'), 'target="_blank" rel="noopener noreferrer nofollow"') !== false);
$check('admin routes read as controller, action, parameters',
    YSRTech_AdminAssistant_Model_Wiki::adminRoute('sales_order') === ['adminhtml/sales_order/index', []]
    && YSRTech_AdminAssistant_Model_Wiki::adminRoute('system_config/edit/section/carriers') === ['adminhtml/system_config/edit', ['section' => 'carriers']]);

echo "\nSaving, history, conflicts\n";
$wiki = Mage::getModel('ysrtech_adminassistant/wiki');
$db   = Mage::getSingleton('core/resource')->getConnection('core_write');
$t    = static fn ($e) => Mage::getSingleton('core/resource')->getTableName('ysrtech_adminassistant/' . $e);
$db->delete($t('wiki_page'), ["url_key LIKE 'aa-test-%'"]);
$user = Mage::getModel('admin/user')->loadByUsername('admin');

$p = $wiki->save(null, ['title' => 'AA test: Packing rules', 'url_key' => 'aa-test-packing', 'category' => 'Orders', 'content' => "Wrap books.\n"], $user);
$check('a new page is saved with a first revision', $p['url_key'] === 'aa-test-packing' && count($wiki->revisions((int) $p['page_id'])) === 1);
$p2 = $wiki->save((int) $p['page_id'], ['title' => $p['title'], 'url_key' => 'aa-test-packing', 'category' => 'Orders', 'content' => "Wrap books twice.\n", 'note' => 'stricter'], $user, (int) $p['version']);
$check('an edit adds a revision with its note', count($wiki->revisions((int) $p['page_id'])) === 2 && $wiki->revisions((int) $p['page_id'])[0]['note'] === 'stricter');
$same = $wiki->save((int) $p['page_id'], ['title' => $p2['title'], 'url_key' => 'aa-test-packing', 'category' => 'Orders', 'content' => "Wrap books twice.\n", 'sort_order' => $p2['sort_order']], $user, (int) $p2['version']);
$check('saving without a change adds no revision', count($wiki->revisions((int) $p['page_id'])) === 2);
try {
    $wiki->save((int) $p['page_id'], ['title' => 'x', 'url_key' => 'aa-test-packing', 'content' => 'mine'], $user, (int) $p['version']); // loaded before the other save, even within the same second
    $check('a save over someone else\'s newer save is refused', false);
} catch (Mage_Core_Exception $e) {
    $check('a save over someone else\'s newer save is refused', stripos($e->getMessage(), 'while you were editing') !== false, $e->getMessage());
}
try {
    $wiki->save(null, ['title' => 'Other', 'url_key' => 'aa-test-packing', 'content' => 'x'], $user);
    $check('two pages cannot share an address', false);
} catch (Mage_Core_Exception $e) {
    $check('two pages cannot share an address', stripos($e->getMessage(), 'already uses') !== false);
}
$check('an address is made from the title', YSRTech_AdminAssistant_Model_Wiki::slug('Refund an order (credit memo)!') === 'refund-an-order-credit-memo');

$wiki->delete((int) $p['page_id'], $user);
$check('a deleted page is gone from the wiki', $wiki->load('aa-test-packing') === null && !isset($wiki->tree()['Orders']) || !in_array('aa-test-packing', array_column($wiki->tree()['Orders'] ?? [], 'url_key'), true));
$first = end($wiki->revisions((int) $p['page_id']));
$back  = $wiki->restore((int) $first['revision_id'], $user);
$check('and can be restored from its history, at any earlier version', $back['url_key'] === 'aa-test-packing' && $back['content'] === "Wrap books.\n");

echo "\nStarter pages\n";
$dir = Mage::getBaseDir('var') . '/aa-wiki-seed-test';
@mkdir($dir . '/10-x', 0777, true);
$write = static fn ($content) => file_put_contents($dir . '/10-x/aa-test-seed.md', "---\ntitle: AA test seed\ncategory: Test\nsort: 5\n---\n" . $content);
$write("Version one.\n");
$c = $wiki->seed($dir);
$check('a new starter page is added', $c['added'] === 1 && $wiki->load('aa-test-seed')['content'] === "Version one.\n", json_encode($c));
$write("Version two.\n");
$c = $wiki->seed($dir);
$check('an untouched starter page is updated with the module', $c['updated'] === 1 && $wiki->load('aa-test-seed')['content'] === "Version two.\n", json_encode($c));
$s = $wiki->load('aa-test-seed');
$wiki->save((int) $s['page_id'], ['title' => $s['title'], 'url_key' => 'aa-test-seed', 'category' => 'Test', 'content' => "Our own words.\n"], $user, (int) $s['version']);
$write("Version three.\n");
$c = $wiki->seed($dir);
$check('an edited starter page is left alone', $c['updated'] === 0 && $wiki->load('aa-test-seed')['content'] === "Our own words.\n", json_encode($c));
$wiki->delete((int) $s['page_id'], $user);
$c = $wiki->seed($dir);
$check('a deleted starter page is not brought back', $c['added'] === 0 && $wiki->load('aa-test-seed') === null, json_encode($c));
unlink($dir . '/10-x/aa-test-seed.md');
rmdir($dir . '/10-x');
rmdir($dir);

echo "\nThe shipped starter pages\n";
$files = glob(YSRTech_AdminAssistant_Model_Wiki::seedDir() . '/*/*.md');
$keys  = array_map(static fn ($f) => basename($f, '.md'), $files);
$check(count($files) . ' starter pages, each with a title and category', $files && count(array_filter(array_map([YSRTech_AdminAssistant_Model_Wiki::class, 'parseSeed'], $files), static fn ($p) => $p && $p['title'] !== '' && $p['category'] !== '')) === count($files));
$broken = [];
foreach ($files as $f) {
    $text = preg_replace('/`[^`]*`/', '', (string) file_get_contents($f)); // examples in code are not links
    preg_match_all('/\[\[([a-z0-9-]+)/', $text, $m);
    foreach ($m[1] as $k) {
        if (!in_array($k, $keys, true)) {
            $broken[] = basename($f) . ' -> ' . $k;
        }
    }
}
$check('every [[link]] between starter pages leads to a page', !$broken, implode(', ', $broken));

echo "\nSearch\n";
$hits = $wiki->search('refund credit memo');
$check('"refund credit memo" finds the refund page first', ($hits[0]['url_key'] ?? '') === 'refund-an-order', json_encode(array_column($hits, 'url_key')));
$hits = $wiki->search('how do I create a coupon');
$check('"how do I create a coupon" finds the coupon page first', ($hits[0]['url_key'] ?? '') === 'coupons-and-cart-rules', json_encode(array_column($hits, 'url_key')));
$hits = $wiki->search('pos sync');
$check('module pages are found too', in_array('pos-sync', array_column($hits, 'url_key'), true));
$check('each result has a snippet', ($hits[0]['excerpt'] ?? '') !== '');

echo "\nThe assistant's wiki look-ups\n";
Mage::getSingleton('ysrtech_adminassistant/audit')->begin(null, null);
$help  = Mage::getSingleton('ysrtech_adminassistant/tool_help')->setAclCheck(static fn () => true);
$tools = Mage::getModel('ysrtech_adminassistant/tools')->setAclCheck(static fn () => true);
$out   = $tools->call('wiki_search', json_encode(['query' => 'ship an order tracking']));
$data  = json_decode($out['content'], true);
$check('wiki_search finds the page', $out['ok'] && ($data['pages'][0]['page'] ?? '') === 'ship-an-order', $out['content']);
$out   = $tools->call('wiki_page', json_encode(['page' => 'ship-an-order']));
$check('wiki_page returns the page', $out['ok'] && strpos($out['content'], 'Add Tracking Number') !== false);
$out   = $tools->call('wiki_page', json_encode(['page' => 'no-such-page']));
$check('a missing page is a clear error', !$out['ok'] && stripos($out['content'], 'no wiki page') !== false);
$help->setAclCheck(static fn ($r) => strpos($r, 'ysrtech_wiki') === false);
$out = $tools->call('wiki_search', json_encode(['query' => 'refund']));
$check('a role without the wiki cannot search it through the assistant', !$out['ok'] && stripos($out['content'], 'wiki') !== false, $out['content']);
$help->setAclCheck(null);
$prompt = Mage::getModel('ysrtech_adminassistant/agent')->setTools($tools)->systemPrompt();
$check('the prompt says to search the wiki first for how-to questions', stripos($prompt, 'wiki_search') !== false && stripos($prompt, 'wins over general knowledge') !== false);

$db->delete($t('wiki_page'), ["url_key LIKE 'aa-test-%'"]);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);

<?php
/**
 * For "how do I..." questions: where things are in this store's own admin.
 *
 * The model knows OpenMage in general; these say where a page or a setting
 * actually is here - add-on modules' menus included - and only what the
 * person asking is allowed to open, so the directions it gives can be
 * followed.
 */
class YSRTech_AdminAssistant_Model_Tool_Help extends YSRTech_AdminAssistant_Model_Tool_Abstract
{
    protected string $_acl = 'help';

    /** @var callable|null fn(string $resource): bool; for tests */
    private $_aclCheck;

    public function setAclCheck(?callable $check): self
    {
        $this->_aclCheck = $check;

        return $this;
    }

    private function _allowed(string $resource): bool
    {
        if ($this->_aclCheck) {
            return (bool) call_user_func($this->_aclCheck, $resource);
        }

        return (bool) Mage::getSingleton('admin/session')->isAllowed($resource);
    }

    public function definitions(): array
    {
        return [
            'admin_menu' => [
                'description' => 'This store\'s admin menu, as the person asking sees it (only pages their role can open), add-on modules included, each with its full path (e.g. "Sales > Orders") and link. Search by words, or omit the query for the top two levels. Use it to tell someone where to go to do something.',
                'parameters'  => ['properties' => [
                    'query' => ['type' => 'string', 'description' => 'Words to look for in menu names, e.g. "credit memo", "coupon", "newsletter"'],
                ]],
            ],
            'wiki_search' => [
                'description' => 'Search this store\'s wiki: how-to pages for OpenMage and every YSRTech module, written for and by this store\'s staff. Returns matching pages with a snippet. Search it first for any how-to or "how does X work" question, then read the best page with wiki_page.',
                'parameters'  => ['properties' => [
                    'query' => ['type' => 'string', 'description' => 'Words to look for, e.g. "credit memo refund", "schedule campaign", "pos sync"'],
                ], 'required' => ['query']],
            ],
            'wiki_page' => [
                'description' => 'Read one wiki page in full, by its address from wiki_search. Answer from it, and link the page.',
                'parameters'  => ['properties' => [
                    'page' => ['type' => 'string', 'description' => 'The page address (url_key)'],
                ], 'required' => ['page']],
            ],
            'settings_search' => [
                'description' => 'Find where a setting lives under System > Configuration in this store: matches setting names, groups, sections and their help text. Returns the full path to click through, the config path, its current value (secret settings withheld) and a link to the page. Use it for "where do I change..." questions.',
                'parameters'  => ['properties' => [
                    'query' => ['type' => 'string', 'description' => 'Words describing the setting, e.g. "order email sender", "free shipping minimum", "guest checkout"'],
                ], 'required' => ['query']],
            ],
        ];
    }

    public function run(string $name, array $args): array
    {
        if ($name === 'admin_menu') {
            return $this->_menu($args);
        }

        if ($name === 'settings_search') {
            return $this->_settings($args);
        }

        if ($name === 'wiki_search' || $name === 'wiki_page') {
            if (!$this->_allowed('admin/' . YSRTech_AdminAssistant_Model_Wiki::ACL_READ)) {
                Mage::throwException('This admin user\'s role does not include the wiki.');
            }

            return $name === 'wiki_search' ? $this->_wikiSearch($args) : $this->_wikiPage($args);
        }

        Mage::throwException('Unknown tool');
    }

    /**
     * @return string[] Lower-case words of a query, without the filler
     */
    private function _words(string $query): array
    {
        $stop  = ['how', 'do', 'i', 'to', 'the', 'a', 'an', 'where', 'can', 'is', 'of', 'for', 'in', 'on', 'my', 'change', 'set', 'find', 'setting', 'settings'];
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [];

        return array_values(array_filter($words, static fn ($w) => $w !== '' && !in_array($w, $stop, true)));
    }

    /** How many of the words appear in the text */
    private function _score(array $words, string $text): int
    {
        $text = mb_strtolower($text);
        $n    = 0;

        foreach ($words as $w) {
            // "emails" finds "email"; "shipping" finds "ship"
            if (mb_strpos($text, $w) !== false || (mb_strlen($w) > 4 && mb_strpos($text, rtrim($w, 's')) !== false)) {
                $n++;
            }
        }

        return $n;
    }

    private function _label(Varien_Simplexml_Element $node, string $field = 'title'): string
    {
        $text   = (string) $node->{$field};
        $module = (string) ($node->getAttribute('module') ?: 'adminhtml');

        if ($text === '') {
            return '';
        }

        // Through the translator, as a helper's __() does, without building
        // the module's helper: some (RSS's) start a session when built
        try {
            $class = Mage::getConfig()->getHelperClassName(strpos($module, '/') === false ? $module . '/data' : $module);
            $name  = implode('_', array_slice(explode('_', $class), 0, 2));

            return (string) Mage::app()->getTranslator()->translate([new Mage_Core_Model_Translate_Expr($text, $name)]);
        } catch (Throwable $e) {
            return $text;
        }
    }

    /**
     * The menu, flattened: one entry per page the person may open.
     *
     * @return array<int, array{path:string, depth:int, url:string, resource:string}>
     */
    public function menuEntries(): array
    {
        $menu = Mage::getSingleton('admin/config')->getAdminhtmlConfig()->getNode('menu');
        $out  = [];

        if ($menu) {
            $this->_walk($menu, [], [], $out);
        }

        return $out;
    }

    private function _walk(Varien_Simplexml_Element $node, array $labels, array $ids, array &$out): void
    {
        $children = [];

        foreach ($node->children() as $id => $child) {
            if ((string) $child->disabled === '1') {
                continue;
            }
            if ($child->depends) {
                if ($child->depends->module && !Mage::helper('core')->isModuleEnabled((string) $child->depends->module)) {
                    continue;
                }
                if ($child->depends->config && !Mage::getStoreConfigFlag((string) $child->depends->config)) {
                    continue;
                }
            }
            $children[] = [(int) $child->sort_order, $id, $child];
        }

        usort($children, static fn ($a, $b) => $a[0] <=> $b[0]);

        foreach ($children as [, $id, $child]) {
            $path     = array_merge($ids, [$id]);
            $resource = 'admin/' . implode('/', $path);

            // The menu itself shows nothing a role is not allowed, and neither does this
            if (!$this->_allowed($resource)) {
                continue;
            }

            $title   = $this->_label($child);
            $trail   = array_merge($labels, [$title]);
            $action  = (string) $child->action;
            $out[]   = [
                'path'     => implode(' > ', $trail),
                'depth'    => count($trail),
                'url'      => $action !== '' ? $this->_adminUrl($action, []) : '',
                'resource' => $resource,
            ];

            if ($child->children) {
                $this->_walk($child->children, $trail, $path, $out);
            }
        }
    }

    private function _menu(array $args): array
    {
        $entries = $this->menuEntries();
        $words   = $this->_words((string) ($args['query'] ?? ''));

        // What people say, and what the admin calls it
        $aliases = [
            'coupon' => ['price', 'rules'], 'coupons' => ['price', 'rules'], 'discount' => ['price', 'rules'], 'promo' => ['price', 'rules'], 'sale' => ['price', 'rules'],
            'refund' => ['credit', 'memos'], 'refunds' => ['credit', 'memos'], 'return' => ['credit', 'memos'],
            'reindex' => ['index', 'management'], 'index' => ['index', 'management'], 'cache' => ['cache', 'management'],
            'staff' => ['users'], 'employee' => ['users'], 'password' => ['account'], 'stock' => ['manage', 'products'],
            'product' => ['manage', 'products'], 'products' => ['manage', 'products'], 'email' => ['transactional', 'emails'],
            'abandoned' => ['abandoned', 'carts'], 'cart' => ['carts'], 'review' => ['reviews'], 'translate' => ['translations'],
            'redirect' => ['url', 'rewrite'], 'page' => ['pages'], 'banner' => ['static', 'blocks'], 'block' => ['blocks'],
        ];
        foreach ($words as $w) {
            foreach ($aliases[$w] ?? [] as $alias) {
                $words[] = $alias;
            }
        }
        $words = array_values(array_unique($words));

        if (!$words) {
            $list = array_values(array_filter($entries, static fn ($e) => $e['depth'] <= 2));

            return ['rows' => count($list), 'data' => ['menu' => array_map(static fn ($e) => array_filter(['path' => $e['path'], 'link' => $e['url']]), $list)]];
        }

        $scored = [];
        foreach ($entries as $e) {
            $score = $this->_score($words, $e['path']);
            if ($score > 0) {
                $scored[] = [$score, $e];
            }
        }

        usort($scored, static fn ($a, $b) => $b[0] <=> $a[0] ?: $a[1]['depth'] <=> $b[1]['depth']);
        $best = $scored ? $scored[0][0] : 0;
        $list = array_slice(array_map(static fn ($s) => $s[1], array_filter($scored, static fn ($s) => $s[0] === $best)), 0, 15);

        return ['rows' => count($list), 'data' => [
            'query'   => (string) $args['query'],
            'matches' => array_map(static fn ($e) => array_filter(['path' => $e['path'], 'link' => $e['url']]), $list),
            'about'   => $list ? 'Only pages this admin\'s role can open are listed.' : 'No menu page by that name that this admin can open. Try other words, or settings_search for a setting.',
        ]];
    }

    private function _settings(array $args): array
    {
        $words = $this->_words((string) ($args['query'] ?? ''));

        if (!$words) {
            Mage::throwException('Say what the setting is about, e.g. "order confirmation email".');
        }

        $config   = Mage::getSingleton('adminhtml/config');
        $sections = $config->getSections();
        $tabs     = $config->getTabs();
        $store    = Mage::getSingleton('ysrtech_adminassistant/tool_store');
        $found    = [];

        foreach ($sections ? $sections->children() : [] as $sectionId => $section) {
            if (!$this->_allowed('admin/system/config/' . $sectionId)) {
                continue;
            }

            $tabId        = (string) $section->tab;
            $tabLabel     = $tabs && $tabs->{$tabId} ? $this->_label($tabs->{$tabId}, 'label') : $tabId;
            $sectionLabel = $this->_label($section, 'label');

            foreach ($section->groups ? $section->groups->children() : [] as $groupId => $group) {
                $groupLabel = $this->_label($group, 'label');

                foreach ($group->fields ? $group->fields->children() : [] as $fieldId => $field) {
                    $type = (string) $field->frontend_type;
                    if (in_array($type, ['button', 'label'], true) || (string) $field->label === '') {
                        continue;
                    }

                    $fieldLabel = $this->_label($field, 'label');
                    $comment    = trim(strip_tags($this->_label($field, 'comment')));
                    $where      = $tabLabel . ' ' . $sectionLabel . ' ' . $groupLabel . ' ' . $fieldLabel;
                    // Names count double: a word in the help text alone is a weaker match
                    $score      = 2 * $this->_score($words, $where) + $this->_score($words, $comment);

                    if ($score > 0) {
                        $found[] = [$score, compact('sectionId', 'groupId', 'fieldId', 'tabLabel', 'sectionLabel', 'groupLabel', 'fieldLabel', 'comment', 'field', 'type')];
                    }
                }
            }
        }

        usort($found, static fn ($a, $b) => $b[0] <=> $a[0]);
        $found = array_slice($found, 0, 12);
        $list  = [];

        foreach ($found as [, $f]) {
            $path = (string) $f['field']->config_path ?: $f['sectionId'] . '/' . $f['groupId'] . '/' . $f['fieldId'];

            if ($store->isSecretPath($path) || in_array($f['type'], ['obscure', 'password'], true)) {
                $value = '(secret; not shown)';
            } else {
                $value = Mage::getStoreConfig($path, 0);
                $value = is_scalar($value) || $value === null ? $this->_optionLabel($f['field'], (string) $value) : '(a group of values)';
            }

            $list[] = array_filter([
                'where'       => 'System > Configuration > ' . $f['tabLabel'] . ' > ' . $f['sectionLabel'] . ' > ' . $f['groupLabel'] . ' > ' . $f['fieldLabel'],
                'config_path' => $path,
                'value'       => $value,
                'can_differ_per' => trim(((string) $f['field']->show_in_website === '1' ? 'website ' : '') . ((string) $f['field']->show_in_store === '1' ? 'store view' : '')) ?: null,
                'help_text'   => $f['comment'] !== '' ? mb_substr($f['comment'], 0, 300) : null,
                'link'        => $this->_adminUrl('adminhtml/system_config/edit', ['section' => $f['sectionId']]),
            ], static fn ($v) => $v !== null && $v !== '');
        }

        return ['rows' => count($list), 'data' => [
            'query'    => (string) $args['query'],
            'settings' => $list,
            'about'    => 'Values shown are the default scope; a website or store view can override them. Only sections this admin may open are searched.',
        ]];
    }

    private function _wikiSearch(array $args): array
    {
        $results = Mage::getSingleton('ysrtech_adminassistant/wiki')->search((string) ($args['query'] ?? ''), 8);

        return ['rows' => count($results), 'data' => [
            'query' => (string) ($args['query'] ?? ''),
            'pages' => array_map(fn ($r) => [
                'page'     => $r['url_key'],
                'title'    => $r['title'],
                'category' => $r['category'],
                'snippet'  => $r['excerpt'],
                'link'     => $this->_adminUrl('adminhtml/ysrtech_wiki/view', ['page' => $r['url_key']]),
            ], $results),
            'about' => $results ? 'Read the best match with wiki_page before answering.' : 'No wiki page matches. Answer from admin_menu, settings_search and general OpenMage knowledge, and say the wiki has no page on it.',
        ]];
    }

    private function _wikiPage(array $args): array
    {
        $wiki = Mage::getSingleton('ysrtech_adminassistant/wiki');
        $page = $wiki->load(YSRTech_AdminAssistant_Model_Wiki::slug((string) ($args['page'] ?? '')));

        if (!$page) {
            Mage::throwException('There is no wiki page with that address. Use wiki_search to find one.');
        }

        // Links as real addresses, so the model can pass them on and the chat
        // can show them: (admin:route) becomes the admin URL with its secret
        // key, and [[page|text]] a link to that wiki page
        $markdown = preg_replace_callback('/\]\(admin:([a-z0-9_\/.-]+)\)/i', function ($m) {
            [$path, $params] = YSRTech_AdminAssistant_Model_Wiki::adminRoute($m[1]);
            $url = $this->_adminUrl($path, $params);

            return $url !== '' ? '](' . $url . ')' : $m[0];
        }, (string) $page['content']);
        $titles   = $wiki->titles();
        $markdown = preg_replace_callback('/\[\[([a-z0-9][a-z0-9-]*)(?:\|([^\]]+))?\]\]/i', function ($m) use ($titles) {
            $key = strtolower($m[1]);
            $url = $this->_adminUrl('adminhtml/ysrtech_wiki/view', ['page' => $key]);
            $txt = isset($m[2]) && $m[2] !== '' ? $m[2] : ($titles[$key] ?? $key);

            return $url !== '' ? '[' . $txt . '](' . $url . ')' : $m[0];
        }, $markdown);
        $page['content'] = $markdown;

        return ['rows' => 1, 'data' => [
            'page'         => $page['url_key'],
            'title'        => $page['title'],
            'category'     => $page['category'],
            'link'         => $this->_adminUrl('adminhtml/ysrtech_wiki/view', ['page' => $page['url_key']]),
            'last_changed' => self::local($page['updated_at']) . ($page['updated_by'] !== '' ? ' by ' . $page['updated_by'] : ' (starter page)'),
            'markdown'     => mb_substr($page['content'], 0, 20000),
            'about'        => 'Written by this store\'s staff (or shipped as a starter page). Its links are real admin addresses; pass them on as they are.',
        ]];
    }

    /** A select setting's value as its option's label, e.g. "Yes" rather than 1 */
    private function _optionLabel(Varien_Simplexml_Element $field, string $value): string
    {
        $source = (string) $field->source_model;

        if ($source === '' || $value === '') {
            return mb_substr($value, 0, 200);
        }

        try {
            $model   = strpos($source, '::') !== false ? null : Mage::getSingleton($source);
            $options = $model && method_exists($model, 'toOptionArray') ? $model->toOptionArray() : [];

            foreach ($options as $option) {
                if (is_array($option) && isset($option['value']) && !is_array($option['value']) && (string) $option['value'] === $value) {
                    return (string) $option['label'] . ' (' . $value . ')';
                }
            }
        } catch (Throwable $e) {
            // Fall back to the stored value
        }

        return mb_substr($value, 0, 200);
    }
}

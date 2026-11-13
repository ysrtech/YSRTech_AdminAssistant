<?php
/**
 * The admin wiki: pages, their revisions, search, and the starter pages the
 * module ships.
 *
 * Starter pages live as Markdown files under the module's wiki/ directory.
 * Installing or upgrading the module adds any that are missing, and updates
 * one only while nobody has edited it: a page someone has changed is theirs,
 * and a page someone deleted stays deleted.
 */
class YSRTech_AdminAssistant_Model_Wiki
{
    public const ACL_READ = 'ysrtech_wiki';

    public const ACL_EDIT = 'ysrtech_wiki/edit';

    public const MAX_CONTENT = 200000;

    private function _db(): Varien_Db_Adapter_Interface
    {
        return Mage::getSingleton('core/resource')->getConnection('core_write');
    }

    private function _t(string $entity): string
    {
        return Mage::getSingleton('core/resource')->getTableName('ysrtech_adminassistant/' . $entity);
    }

    public static function slug(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);

        return substr(trim($text, '-'), 0, 120);
    }

    // ------------------------------------------------------------------ reading

    public function load(string $urlKey, bool $withDeleted = false): ?array
    {
        $db     = $this->_db();
        $select = $db->select()->from($this->_t('wiki_page'))->where('url_key = ?', $urlKey);

        if (!$withDeleted) {
            $select->where('is_deleted = 0');
        }

        $row = $db->fetchRow($select);

        return $row ?: null;
    }

    public function loadById(int $id): ?array
    {
        $db  = $this->_db();
        $row = $db->fetchRow($db->select()->from($this->_t('wiki_page'))->where('page_id = ?', $id)->where('is_deleted = 0'));

        return $row ?: null;
    }

    /**
     * @return array<string, string> url_key => title, of every page
     */
    public function titles(): array
    {
        static $titles = null;

        if ($titles === null) {
            $db     = $this->_db();
            $titles = $db->fetchPairs($db->select()->from($this->_t('wiki_page'), ['url_key', 'title'])->where('is_deleted = 0'));
        }

        return $titles;
    }

    /**
     * Pages grouped by category, categories in order of their first page.
     *
     * @return array<string, array<int, array{url_key:string, title:string, updated_at:string}>>
     */
    public function tree(): array
    {
        $db   = $this->_db();
        $rows = $db->fetchAll($db->select()
            ->from($this->_t('wiki_page'), ['url_key', 'title', 'category', 'sort_order', 'updated_at'])
            ->where('is_deleted = 0')
            ->order(['sort_order ASC', 'title ASC']));

        $tree  = [];
        $order = [];

        foreach ($rows as $r) {
            $cat = $r['category'] !== '' ? $r['category'] : 'General';
            $order[$cat] = min($order[$cat] ?? PHP_INT_MAX, (int) $r['sort_order']);
            $tree[$cat][] = $r;
        }

        uksort($tree, static fn ($a, $b) => $order[$a] <=> $order[$b] ?: strcmp($a, $b));

        return $tree;
    }

    public function categories(): array
    {
        return array_keys($this->tree());
    }

    public function recent(int $limit = 8): array
    {
        $db = $this->_db();

        return $db->fetchAll($db->select()
            ->from(['r' => $this->_t('wiki_revision')], ['created_at', 'username', 'note'])
            ->join(['p' => $this->_t('wiki_page')], 'p.page_id = r.page_id', ['url_key', 'title'])
            ->where('p.is_deleted = 0')
            ->where("r.username != ''")
            ->order('r.revision_id DESC')->limit($limit));
    }

    /**
     * Pages matching the words, best first, each with a short excerpt.
     *
     * @return array<int, array{url_key:string, title:string, category:string, excerpt:string, score:int}>
     */
    public function search(string $query, int $limit = 20): array
    {
        $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [], static fn ($w) => mb_strlen($w) > 1
            && !in_array($w, ['how', 'do', 'the', 'to', 'an', 'is', 'of', 'in', 'on', 'my', 'can', 'where', 'what', 'for', 'and', 'or', 'with'], true)));

        if (!$words) {
            return [];
        }

        // Plain endings off, so "showing" finds "show" and "refunds" finds "refund"
        $words = array_values(array_unique(array_map(static function ($w) {
            foreach (['ing' => 5, 'ed' => 4, 'es' => 4, 's' => 3] as $end => $min) {
                if (mb_strlen($w) > $min + 1 && mb_substr($w, -mb_strlen($end)) === $end) {
                    return mb_substr($w, 0, -mb_strlen($end));
                }
            }

            return $w;
        }, $words)));

        $db     = $this->_db();
        $select = $db->select()->from($this->_t('wiki_page'), ['url_key', 'title', 'category', 'content'])->where('is_deleted = 0');
        $any    = [];

        foreach ($words as $w) {
            $like  = '%' . addcslashes($w, '%_\\') . '%';
            $any[] = $db->quoteInto('(title LIKE ? OR content LIKE ? OR category LIKE ?)', $like);
        }

        $rows  = $db->fetchAll($select->where(implode(' OR ', $any))->limit(300));
        $total = max(1, (int) $db->fetchOne($db->select()->from($this->_t('wiki_page'), [new Zend_Db_Expr('COUNT(*)')])->where('is_deleted = 0')));
        $out   = [];

        // A word on few pages says more than one on many: "coupon" outweighs "create"
        $weight = [];
        foreach ($words as $w) {
            $df = 0;
            foreach ($rows as $r) {
                if (mb_strpos(mb_strtolower($r['title'] . ' ' . $r['content']), $w) !== false) {
                    $df++;
                }
            }
            $weight[$w] = log(1 + $total / max(1, $df));

            // Words that say what to do, not what about, count for little
            if (in_array($w, ['create', 'add', 'make', 'change', 'set', 'edit', 'find', 'use', 'get', 'new', 'update', 'show', 'see', 'remove', 'delete', 'not', 'why', 'work'], true)) {
                $weight[$w] *= 0.3;
            }
        }

        foreach ($rows as $r) {
            $title   = mb_strtolower($r['title']);
            $content = mb_strtolower($r['content']);
            $score   = 0.0;
            $hits    = 0;

            foreach ($words as $w) {
                $inTitle   = mb_strpos($title, $w) !== false;
                $inContent = mb_substr_count($content, $w);
                if ($inTitle || $inContent) {
                    $hits++;
                }
                $score += $weight[$w] * (($inTitle ? 10 : 0) + min(5, $inContent) + (mb_strpos(mb_strtolower($r['category']), $w) !== false ? 3 : 0));
            }

            // Having every word breaks ties; it does not outweigh a better match
            $score += $hits === count($words) ? 8 : 0;
            $score  = (int) round($score * 10);
            $out[]  = ['url_key' => $r['url_key'], 'title' => $r['title'], 'category' => $r['category'], 'excerpt' => $this->excerpt($r['content'], $words), 'score' => $score];
        }

        usort($out, static fn ($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['title'], $b['title']));

        return array_slice($out, 0, $limit);
    }

    /** A plain-text snippet around the first of the words */
    public function excerpt(string $markdown, array $words, int $length = 220): string
    {
        $text = trim(preg_replace('/\s+/', ' ', preg_replace(['/```.*?```/s', '/[#>*`|_\[\]]+/', '/\(admin:[^)]*\)|\(https?:[^)]*\)/'], ' ', $markdown)));
        $low  = mb_strtolower($text);
        $at   = 0;

        foreach ($words as $w) {
            $p = mb_strpos($low, $w);
            if ($p !== false) {
                $at = max(0, $p - 60);
                break;
            }
        }

        $snippet = mb_substr($text, $at, $length);

        return ($at > 0 ? '…' : '') . $snippet . (mb_strlen($text) > $at + $length ? '…' : '');
    }

    public function revisions(int $pageId): array
    {
        $db = $this->_db();

        return $db->fetchAll($db->select()->from($this->_t('wiki_revision'), ['revision_id', 'title', 'username', 'note', 'created_at', 'length' => new Zend_Db_Expr('CHAR_LENGTH(content)')])
            ->where('page_id = ?', $pageId)->order('revision_id DESC'));
    }

    public function revision(int $revisionId): ?array
    {
        $db  = $this->_db();
        $row = $db->fetchRow($db->select()->from($this->_t('wiki_revision'))->where('revision_id = ?', $revisionId));

        return $row ?: null;
    }

    // ------------------------------------------------------------------ writing

    /**
     * Save a page. $expectedVersion is the page's version as the editor
     * loaded it: if someone saved in between, nothing is written.
     *
     * @return array The saved page
     * @throws Mage_Core_Exception With a message fit to show
     */
    public function save(?int $pageId, array $data, ?Mage_Admin_Model_User $user, ?int $expectedVersion = null): array
    {
        $title    = trim(preg_replace('/\s+/', ' ', (string) ($data['title'] ?? '')));
        $category = trim(preg_replace('/\s+/', ' ', (string) ($data['category'] ?? '')));
        $content  = str_replace(["\r\n", "\r"], "\n", (string) ($data['content'] ?? ''));
        $urlKey   = self::slug((string) ($data['url_key'] ?? '') ?: $title);
        $sort     = (int) ($data['sort_order'] ?? 500);
        $note     = mb_substr(trim((string) ($data['note'] ?? '')), 0, 255);

        if ($title === '') {
            Mage::throwException('A page needs a title.');
        }

        if ($urlKey === '') {
            Mage::throwException('The page address can only use letters, digits and dashes.');
        }

        if (strlen($content) > self::MAX_CONTENT) {
            Mage::throwException('The page is too long. Split it into several pages.');
        }

        $db       = $this->_db();
        $now      = gmdate('Y-m-d H:i:s');
        $existing = $pageId ? $this->loadById($pageId) : null;
        $clash    = $this->load($urlKey, true);

        if ($pageId && !$existing) {
            Mage::throwException('That page no longer exists.');
        }

        if ($clash && (!$existing || (int) $clash['page_id'] !== (int) $existing['page_id'])) {
            Mage::throwException($clash['is_deleted'] ? 'A deleted page used the address "' . $urlKey . '". Choose another, or restore that page from its history.' : 'Another page already uses the address "' . $urlKey . '".');
        }

        if ($existing && $expectedVersion !== null && $expectedVersion !== (int) $existing['version']) {
            Mage::throwException(sprintf('%s saved this page while you were editing it. Copy your text, reload the page, and apply your change again.', $existing['updated_by'] ?: 'Someone'));
        }

        $row = [
            'url_key'    => $urlKey,
            'title'      => mb_substr($title, 0, 255),
            'category'   => mb_substr($category, 0, 120),
            'sort_order' => $sort,
            'content'    => $content,
            'updated_at' => $now,
            'updated_by' => $user ? (string) $user->getUsername() : '',
        ];

        if ($existing) {
            if ($existing['title'] === $row['title'] && $existing['category'] === $row['category'] && $existing['content'] === $content
                && $existing['url_key'] === $urlKey && (int) $existing['sort_order'] === $sort) {
                return $existing; // nothing changed: no revision
            }
            // The version check again in the write itself: two saves at once
            // cannot both pass the check above and both be written
            $done = $db->update($this->_t('wiki_page'), $row + ['version' => new Zend_Db_Expr('version + 1')], [
                'page_id = ?' => (int) $existing['page_id'],
                'version = ?' => (int) $existing['version'],
            ]);
            if (!$done) {
                Mage::throwException('Someone saved this page at the same moment. Copy your text, reload the page, and apply your change again.');
            }
            $id = (int) $existing['page_id'];
        } else {
            $db->insert($this->_t('wiki_page'), $row + ['created_at' => $now, 'is_deleted' => 0]);
            $id = (int) $db->lastInsertId();
        }

        $this->_revision($id, $row, $user ? (string) $user->getUsername() : '', $note ?: ($existing ? '' : 'Created'));

        return $this->loadById($id);
    }

    public function delete(int $pageId, ?Mage_Admin_Model_User $user): void
    {
        $page = $this->loadById($pageId);

        if (!$page) {
            return;
        }

        // Kept, marked deleted: its history stays, and a starter page is not
        // brought back by the next upgrade
        $this->_db()->update($this->_t('wiki_page'), ['is_deleted' => 1, 'updated_at' => gmdate('Y-m-d H:i:s'), 'updated_by' => $user ? (string) $user->getUsername() : ''], ['page_id = ?' => $pageId]);
        $this->_revision($pageId, $page, $user ? (string) $user->getUsername() : '', 'Deleted');
    }

    public function restore(int $revisionId, ?Mage_Admin_Model_User $user): array
    {
        $rev = $this->revision($revisionId);

        if (!$rev) {
            Mage::throwException('That revision no longer exists.');
        }

        $db   = $this->_db();
        $page = $db->fetchRow($db->select()->from($this->_t('wiki_page'))->where('page_id = ?', (int) $rev['page_id']));
        $db->update($this->_t('wiki_page'), ['is_deleted' => 0], ['page_id = ?' => (int) $rev['page_id']]);

        return $this->save((int) $rev['page_id'], [
            'title' => $rev['title'], 'category' => $rev['category'], 'content' => $rev['content'],
            'url_key' => $page['url_key'], 'sort_order' => $page['sort_order'],
            'note' => 'Restored the version of ' . YSRTech_AdminAssistant_Model_Tool_Abstract::local($rev['created_at']),
        ], $user);
    }

    private function _revision(int $pageId, array $row, string $username, string $note): void
    {
        $this->_db()->insert($this->_t('wiki_revision'), [
            'page_id'    => $pageId,
            'title'      => $row['title'],
            'category'   => $row['category'],
            'content'    => $row['content'],
            'username'   => mb_substr($username, 0, 64),
            'note'       => mb_substr($note, 0, 255),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    // ------------------------------------------------------------------ rendering

    public function markdown(): YSRTech_AdminAssistant_Model_Wiki_Markdown
    {
        $titles = $this->titles();

        return new YSRTech_AdminAssistant_Model_Wiki_Markdown([
            'page_title' => static fn (string $k) => $titles[$k] ?? null,
            'page_url'   => static fn (string $k) => PHP_SAPI === 'cli' ? '#' . $k : Mage::helper('adminhtml')->getUrl('adminhtml/ysrtech_wiki/view', ['page' => $k]),
            'admin_url'  => static function (string $route) {
                if (PHP_SAPI === 'cli') {
                    return '#' . $route;
                }
                return Mage::helper('adminhtml')->getUrl(...self::adminRoute($route));
            },
        ]);
    }

    /**
     * An admin: link's route as getUrl() arguments: controller, then action
     * (index when absent), then key/value pairs.
     * "system_config/edit/section/carriers" is the Shipping Methods settings.
     *
     * @return array{0:string, 1:array<string, string>}
     */
    public static function adminRoute(string $route): array
    {
        $parts  = explode('/', trim($route, '/'));
        $path   = 'adminhtml/' . $parts[0] . '/' . ($parts[1] ?? 'index');
        $params = [];

        for ($i = 2; $i + 1 < count($parts); $i += 2) {
            $params[$parts[$i]] = $parts[$i + 1];
        }

        return [$path, $params];
    }

    // ------------------------------------------------------------------ starter pages

    public static function seedDir(): string
    {
        return Mage::getModuleDir('', 'YSRTech_AdminAssistant') . DS . 'wiki';
    }

    /**
     * Read one starter page file: front matter (title, category, sort) then Markdown.
     */
    public static function parseSeed(string $file): ?array
    {
        $raw = (string) file_get_contents($file);

        if (!preg_match('/^---\n(.*?)\n---\n(.*)$/s', str_replace("\r\n", "\n", $raw), $m)) {
            return null;
        }

        $meta = [];
        foreach (explode("\n", $m[1]) as $line) {
            if (preg_match('/^(\w+):\s*(.*)$/', $line, $mm)) {
                $meta[$mm[1]] = trim($mm[2], " \"'");
            }
        }

        return [
            'url_key'    => self::slug(basename($file, '.md')),
            'title'      => $meta['title'] ?? basename($file, '.md'),
            'category'   => $meta['category'] ?? 'General',
            'sort_order' => (int) ($meta['sort'] ?? 500),
            'content'    => trim($m[2]) . "\n",
        ];
    }

    public static function hash(array $page): string
    {
        return sha1($page['title'] . "\0" . $page['category'] . "\0" . $page['content']);
    }

    /**
     * Add missing starter pages; update those nobody has edited.
     *
     * @return array{added:int, updated:int, kept:int}
     */
    public function seed(?string $dir = null): array
    {
        $dir   = $dir ?? self::seedDir();
        $db    = $this->_db();
        $now   = gmdate('Y-m-d H:i:s');
        $count = ['added' => 0, 'updated' => 0, 'kept' => 0];

        foreach (glob($dir . DS . '*' . DS . '*.md') ?: [] as $file) {
            $page = self::parseSeed($file);

            if (!$page || $page['url_key'] === '') {
                continue;
            }

            $hash     = self::hash($page);
            $existing = $this->load($page['url_key'], true);

            if (!$existing) {
                $db->insert($this->_t('wiki_page'), $page + ['seed_hash' => $hash, 'created_at' => $now, 'updated_at' => $now, 'updated_by' => '', 'is_deleted' => 0]);
                $this->_revision((int) $db->lastInsertId(), $page, '', 'Starter page');
                $count['added']++;
                continue;
            }

            $untouched = !$existing['is_deleted'] && $existing['seed_hash'] !== null && self::hash($existing) === $existing['seed_hash'];

            if ($untouched && $existing['seed_hash'] !== $hash) {
                $db->update($this->_t('wiki_page'), $page + ['seed_hash' => $hash, 'updated_at' => $now, 'updated_by' => '', 'version' => new Zend_Db_Expr('version + 1')], ['page_id = ?' => (int) $existing['page_id']]);
                $this->_revision((int) $existing['page_id'], $page, '', 'Starter page updated');
                $count['updated']++;
            } else {
                $count['kept']++;
            }
        }

        return $count;
    }
}

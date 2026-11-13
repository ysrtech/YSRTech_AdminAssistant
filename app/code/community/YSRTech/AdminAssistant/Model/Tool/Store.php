<?php
/**
 * The store itself: settings (secrets excluded), cron, recent errors in the
 * logs, and an overview of stores, caches and indexes.
 */
class YSRTech_AdminAssistant_Model_Tool_Store extends YSRTech_AdminAssistant_Model_Tool_Abstract
{
    protected string $_acl = 'store';

    /** A setting whose path matches this is never shown, whatever its field type */
    public const SECRET_PATH = '/(password|passwd|secret|token|api_?key|apikey|private|signature|hash|salt|crypt|credential|auth|license|cert|access_?key|merchant_?key|webhook|_key$|\/key$)/i';

    public const LOGS = ['system' => 'system.log', 'exception' => 'exception.log'];

    /** How much of the end of a log file is read */
    public const LOG_TAIL_BYTES = 262144;

    public function definitions(): array
    {
        return [
            'config_value' => [
                'description' => 'Read store settings. Give an exact path (section/group/field, e.g. "general/store_information/name", "carriers/flatrate/price") for its effective value in each store view, or a path prefix (e.g. "carriers/flatrate") to list the saved settings under it. Passwords, keys and other secret settings are never returned.',
                'parameters'  => ['properties' => [
                    'path'   => ['type' => 'string'],
                    'prefix' => ['type' => 'boolean', 'description' => 'true to list everything saved under the path'],
                ], 'required' => ['path']],
            ],
            'cron_status' => [
                'description' => 'Whether OpenMage\'s cron is running: when it last ran, each job\'s last run and result, what is waiting, and errors in the last 24 hours.',
                'parameters'  => ['properties' => ['job_code' => ['type' => 'string', 'description' => 'Part of a job code to focus on']]],
            ],
            'recent_errors' => [
                'description' => 'The latest entries in var/log/system.log or exception.log, newest first, with likely secrets blanked out. Filter by text.',
                'parameters'  => ['properties' => [
                    'log'      => ['type' => 'string', 'enum' => ['system', 'exception']],
                    'contains' => ['type' => 'string'],
                    'limit'    => ['type' => 'integer', 'description' => 'Entries, default 20'],
                ]],
            ],
            'store_overview' => [
                'description' => 'Websites and store views with their ids, base currency, timezone and locale, OpenMage version, caches switched off, indexes needing a reindex, and the YSRTech modules installed.',
                'parameters'  => ['properties' => new stdClass()],
            ],
        ];
    }

    public function run(string $name, array $args): array
    {
        switch ($name) {
            case 'config_value':   return $this->_config($args);
            case 'cron_status':    return $this->_cron($args);
            case 'recent_errors':  return $this->_errors($args);
            case 'store_overview': return $this->_overview();
        }

        Mage::throwException('Unknown tool');
    }

    /**
     * Paths declared in system.xml as obscured, password or encrypted fields.
     *
     * @return array<string, true>
     */
    public function secretPaths(): array
    {
        static $paths = null;

        if ($paths !== null) {
            return $paths;
        }

        $paths    = [];
        $sections = Mage::getSingleton('adminhtml/config')->getSections();

        if (!$sections) {
            return $paths;
        }

        foreach ($sections->children() as $section) {
            foreach ($section->groups ? $section->groups->children() : [] as $group) {
                foreach ($group->fields ? $group->fields->children() : [] as $field) {
                    $type    = (string) $field->frontend_type;
                    $backend = (string) $field->backend_model;

                    if (in_array($type, ['obscure', 'password'], true) || stripos($backend, 'encrypted') !== false) {
                        $paths[$section->getName() . '/' . $group->getName() . '/' . $field->getName()] = true;
                        $configPath = (string) $field->config_path;
                        if ($configPath !== '') {
                            $paths[$configPath] = true;
                        }
                    }
                }
            }
        }

        return $paths;
    }

    public function isSecretPath(string $path): bool
    {
        $path = trim($path, '/');

        return isset($this->secretPaths()[$path]) || (bool) preg_match(self::SECRET_PATH, $path);
    }

    private function _config(array $args): array
    {
        $path = trim(strtolower((string) ($args['path'] ?? '')), '/ ');

        if (!preg_match('#^[a-z0-9_]+(/[a-z0-9_]+){0,2}$#', $path)) {
            Mage::throwException('A path looks like section/group/field.');
        }

        if (!empty($args['prefix']) || substr_count($path, '/') < 2) {
            $db   = $this->_read();
            $rows = $db->fetchAll($db->select()
                ->from($this->_table('core/config_data'), ['scope', 'scope_id', 'path', 'value'])
                ->where('path LIKE ?', addcslashes($path, '%_\\') . '/%')
                ->order('path ASC')->limit(300));

            $shown = $hidden = [];
            foreach ($rows as $r) {
                if ($this->isSecretPath($r['path'])) {
                    $hidden[] = $r['path'];
                    continue;
                }
                $shown[] = $r;
            }

            return ['rows' => count($shown), 'data' => [
                'note'         => 'Settings saved in the database under this path. A setting never saved uses the module\'s default, which is not listed; ask for its exact path to see it.',
                'settings'     => $shown,
                'withheld'     => array_values(array_unique($hidden)),
                'withheld_why' => $hidden ? 'secret settings are never shown' : null,
            ]];
        }

        if ($this->isSecretPath($path)) {
            return ['rows' => 0, 'data' => ['path' => $path, 'value' => null, 'withheld' => 'This is a secret setting (a password, key or token) and is never shown.']];
        }

        $values = ['default' => Mage::getStoreConfig($path, 0)];

        foreach (Mage::app()->getStores() as $store) {
            $values[$store->getCode() . ' (store ' . $store->getId() . ')'] = Mage::getStoreConfig($path, $store->getId());
        }

        foreach ($values as $k => $v) {
            if (is_array($v)) {
                $values[$k] = 'a group of settings; ask for a full section/group/field path';
            }
        }

        return ['rows' => count($values), 'data' => ['path' => $path, 'values' => $values]];
    }

    private function _cron(array $args): array
    {
        $db    = $this->_read();
        $table = $this->_table('cron/schedule');
        $base  = $db->select()->from($table, []);

        if (!empty($args['job_code'])) {
            $base->where('job_code LIKE ?', $this->_like((string) $args['job_code']));
        }

        $lastRun = $db->fetchOne($db->select()->from($table, [new Zend_Db_Expr('MAX(executed_at)')]));
        $minutes = $lastRun ? (int) round((time() - strtotime($lastRun . ' UTC')) / 60) : null;

        $jobs = $db->fetchAll((clone $base)->columns([
            'job_code',
            'last_success' => new Zend_Db_Expr("MAX(IF(status = 'success', finished_at, NULL))"),
            'last_error'   => new Zend_Db_Expr("MAX(IF(status IN ('error', 'missed'), COALESCE(finished_at, executed_at, scheduled_at), NULL))"),
            'pending'      => new Zend_Db_Expr("SUM(status = 'pending')"),
            'running'      => new Zend_Db_Expr("SUM(status = 'running')"),
        ])->group('job_code')->order('job_code ASC')->limit(150));

        $errors = $db->fetchAll((clone $base)->columns(['job_code', 'status', 'scheduled_at', 'message' => 'messages'])
            ->where("status IN ('error', 'missed')")
            ->where('scheduled_at >= ?', gmdate('Y-m-d H:i:s', time() - 86400))
            ->order('scheduled_at DESC')->limit(20));

        if ($minutes === null) {
            $verdict = 'Cron has never run on this store (the schedule table is empty).';
        } elseif ($minutes > 15) {
            $verdict = sprintf('Cron does not appear to be running: the last job started %d minutes ago.', $minutes);
        } else {
            $verdict = sprintf('Cron is running: the last job started %d minutes ago.', $minutes);
        }

        return ['rows' => count($jobs) + count($errors), 'data' => [
            'verdict'   => $verdict,
            'last_run'  => self::local($lastRun),
            'jobs'      => array_map(fn ($j) => [
                'job'          => $j['job_code'],
                'last_success' => self::local($j['last_success']),
                'last_error'   => self::local($j['last_error']),
                'pending'      => (int) $j['pending'],
                'running'      => (int) $j['running'],
            ], $jobs),
            'errors_last_24h' => array_map(fn ($e) => [
                'job'     => $e['job_code'],
                'status'  => $e['status'],
                'at'      => self::local($e['scheduled_at']),
                'message' => self::redact(mb_substr((string) $e['message'], 0, 600)),
            ], $errors),
        ]];
    }

    /**
     * Blank out what looks like a credential in a log line.
     */
    public static function redact(string $text): string
    {
        $text = preg_replace('/((?:password|passwd|pwd|secret|token|api[_-]?key|apikey|authorization|bearer|key)["\']?\s*[:=>]+\s*["\']?)[^\s"\',&]+/i', '$1[redacted]', $text);
        $text = preg_replace('/\b(?:sk|pk|rk|key)-[A-Za-z0-9_\-]{12,}\b/', '[redacted]', $text);
        $text = preg_replace('/\b[A-Za-z0-9+\/_\-]{40,}={0,2}/', '[redacted]', $text);
        // Card-like digit runs
        $text = preg_replace('/\b(?:\d[ -]?){13,19}\b/', '[redacted number]', $text);

        return (string) $text;
    }

    /**
     * The newest entries of a log file, parsed from its tail.
     *
     * @return array<int, array{at:string, message:string}>
     */
    public static function tail(string $file, int $limit, string $contains = ''): array
    {
        if (!is_file($file) || !is_readable($file)) {
            return [];
        }

        $size = filesize($file);
        $fh   = fopen($file, 'rb');
        fseek($fh, max(0, $size - self::LOG_TAIL_BYTES));
        $text = (string) fread($fh, self::LOG_TAIL_BYTES);
        fclose($fh);

        // Each entry starts with an ISO timestamp at the beginning of a line
        $parts   = preg_split('/^(?=\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})/m', $text) ?: [];
        $entries = [];

        foreach (array_reverse($parts) as $part) {
            $part = trim($part);

            if ($part === '' || !preg_match('/^(\S+)\s+(.*)$/s', $part, $m)) {
                continue;
            }

            if ($contains !== '' && stripos($m[2], $contains) === false) {
                continue;
            }

            $entries[] = ['at' => $m[1], 'message' => self::redact(mb_substr($m[2], 0, 1500))];

            if (count($entries) >= $limit) {
                break;
            }
        }

        return $entries;
    }

    private function _errors(array $args): array
    {
        $log     = self::LOGS[$args['log'] ?? 'exception'] ?? 'exception.log';
        $file    = Mage::getBaseDir('var') . DS . 'log' . DS . $log;
        $entries = self::tail($file, $this->_limit($args), (string) ($args['contains'] ?? ''));

        return ['rows' => count($entries), 'data' => [
            'log'     => 'var/log/' . $log,
            'exists'  => is_file($file),
            'logging' => Mage::getStoreConfigFlag('dev/log/active') ? 'on' : 'off (System > Configuration > Developer > Log Settings)',
            'entries' => $entries,
        ]];
    }

    private function _overview(): array
    {
        $stores = [];

        foreach (Mage::app()->getWebsites() as $website) {
            foreach ($website->getStores() as $store) {
                $stores[] = [
                    'website'    => $website->getCode() . ' (' . $website->getId() . ')',
                    'store_id'   => (int) $store->getId(),
                    'code'       => $store->getCode(),
                    'name'       => $store->getName(),
                    'active'     => (bool) $store->getIsActive(),
                    'locale'     => Mage::getStoreConfig('general/locale/code', $store->getId()),
                    'currency'   => $store->getBaseCurrencyCode(),
                    'timezone'   => Mage::getStoreConfig('general/locale/timezone', $store->getId()),
                ];
            }
        }

        $caches = [];
        foreach (Mage::app()->getCacheInstance()->getTypes() as $code => $type) {
            if (!$type->getStatus()) {
                $caches[] = $code;
            }
        }

        $indexes = [];
        foreach (Mage::getResourceModel('index/process_collection') as $process) {
            if ($process->getStatus() !== Mage_Index_Model_Process::STATUS_PENDING) {
                $indexes[] = $process->getIndexerCode() . ': ' . $process->getStatus();
            }
        }

        $modules = [];
        foreach (Mage::getConfig()->getNode('modules')->children() as $name => $module) {
            if (strpos($name, 'YSRTech_') === 0) {
                $modules[$name] = ((string) $module->active === 'true' ? 'active' : 'inactive') . ', ' . (string) Mage::getConfig()->getNode('modules/' . $name . '/version');
            }
        }

        return ['rows' => count($stores), 'data' => [
            'store_name'        => Mage::getStoreConfig('general/store_information/name'),
            'openmage_version'  => method_exists('Mage', 'getOpenMageVersion') ? Mage::getOpenMageVersion() : Mage::getVersion(),
            'stores'            => $stores,
            'caches_off'        => $caches,
            'indexes_not_ready' => $indexes,
            'ysrtech_modules'   => $modules,
            'now'               => (new DateTime('now', self::timezone()))->format('Y-m-d H:i T'),
        ]];
    }
}

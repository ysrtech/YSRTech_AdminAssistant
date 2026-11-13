<?php
/**
 * Every tool the assistant has, which of them the person asking may use, and
 * the one way a tool is run.
 */
class YSRTech_AdminAssistant_Model_Tools
{
    /** Tool groups, by model alias */
    public const GROUPS = [
        'ysrtech_adminassistant/tool_orders',
        'ysrtech_adminassistant/tool_customers',
        'ysrtech_adminassistant/tool_catalog',
        'ysrtech_adminassistant/tool_sales',
        'ysrtech_adminassistant/tool_campaigns',
        'ysrtech_adminassistant/tool_store',
        'ysrtech_adminassistant/tool_report',
    ];

    /** What the model is sent back from one tool, at most */
    public const MAX_RESULT_CHARS = 24000;

    /** @var array<string, YSRTech_AdminAssistant_Model_Tool_Abstract>|null name => group */
    private ?array $_byName = null;

    /** @var callable|null fn(string $resource): bool; for tests */
    private $_acl;

    public function setAclCheck(?callable $check): self
    {
        $this->_acl = $check;

        return $this;
    }

    public function isAllowed(string $resource): bool
    {
        if ($this->_acl) {
            return (bool) call_user_func($this->_acl, $resource);
        }

        $session = Mage::getSingleton('admin/session');

        return $session->getUser() !== null
            && $session->isAllowed(YSRTech_AdminAssistant_Helper_Data::ACL_USE)
            && $session->isAllowed($resource);
    }

    /**
     * @return array<string, YSRTech_AdminAssistant_Model_Tool_Abstract>
     */
    private function _all(): array
    {
        if ($this->_byName === null) {
            $this->_byName = [];

            foreach (self::GROUPS as $alias) {
                $group = Mage::getSingleton($alias);

                if (!$group instanceof YSRTech_AdminAssistant_Model_Tool_Abstract || !$group->available()) {
                    continue;
                }

                foreach (array_keys($group->definitions()) as $name) {
                    $this->_byName[$name] = $group;
                }
            }
        }

        return $this->_byName;
    }

    /**
     * The function definitions to send the model: only the tools this person
     * may use.
     */
    public function definitionsForUser(): array
    {
        $out = [];

        foreach ($this->_all() as $name => $group) {
            if (!$this->isAllowed($group->aclResource())) {
                continue;
            }

            $def   = $group->definitions()[$name];
            $out[] = [
                'type'     => 'function',
                'function' => [
                    'name'        => $name,
                    'description' => $def['description'],
                    'parameters'  => $def['parameters'] + ['type' => 'object'],
                ],
            ];
        }

        return $out;
    }

    /**
     * @return string[] The names of the tool groups this person may not use, for the system prompt
     */
    public function deniedAreas(): array
    {
        $denied = [];

        foreach (self::GROUPS as $alias) {
            $group = Mage::getSingleton($alias);

            if ($group instanceof YSRTech_AdminAssistant_Model_Tool_Abstract && $group->available() && !$this->isAllowed($group->aclResource())) {
                $denied[] = substr($group->aclResource(), strrpos($group->aclResource(), '/') + 1);
            }
        }

        return $denied;
    }

    /**
     * Run one tool for the person asking, and return what to send the model.
     *
     * @param  string $arguments The model's JSON arguments
     * @return array{content:string, rows:int, ok:bool, label:string}
     */
    public function call(string $name, string $arguments): array
    {
        $h       = Mage::helper('ysrtech_adminassistant');
        $started = microtime(true);
        $args    = trim($arguments) === '' ? [] : json_decode($arguments, true);
        $audit   = Mage::getSingleton('ysrtech_adminassistant/audit');
        $group   = $this->_all()[$name] ?? null;

        $fail = function (string $message, ?array $input = null) use ($audit, $name, $started): array {
            $audit->tool($name, $input ?? [], null, false, $message, (int) ((microtime(true) - $started) * 1000));

            return ['content' => (string) json_encode(['error' => $message]), 'rows' => 0, 'ok' => false, 'label' => $name];
        };

        if (!is_array($args)) {
            return $fail('The arguments were not valid JSON.');
        }

        if ($group === null) {
            return $fail('There is no tool called ' . $name . '.', $args);
        }

        // Checked again here, not only when the list was offered: the model
        // can name a tool it was never given
        if (!$this->isAllowed($group->aclResource())) {
            return $fail('This admin user\'s role does not allow that look-up.', $args);
        }

        $connection = Mage::getSingleton('core/resource')->getConnection('core_read');
        $readOnly   = $this->_beginReadOnly($connection);

        $result = null;
        $error  = null;

        try {
            $result = $group->run($name, $args);
        } catch (Mage_Core_Exception $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            Mage::logException($e);
            $error = 'The look-up failed: ' . mb_substr($e->getMessage(), 0, 200);
        }

        // Before anything is written to the audit log, which the read-only
        // transaction would refuse
        if ($readOnly) {
            try {
                $connection->query('ROLLBACK');
            } catch (Throwable $e) {
                Mage::logException($e);
            }
        }

        if ($error !== null) {
            return $fail($error, $args);
        }

        $data    = Mage::getSingleton('ysrtech_adminassistant/sanitizer')->clean($result['data']);
        $content = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if (mb_strlen($content) > self::MAX_RESULT_CHARS) {
            $content = mb_substr($content, 0, self::MAX_RESULT_CHARS)
                . '… [result cut at ' . self::MAX_RESULT_CHARS . ' characters; ask for fewer rows or narrower filters]';
        }

        $rows = (int) ($result['rows'] ?? 0);
        $audit->tool($name, $args, $rows, true, null, (int) ((microtime(true) - $started) * 1000));

        return ['content' => $content, 'rows' => $rows, 'ok' => true, 'label' => $h->__($name)];
    }

    /**
     * A read-only transaction around the tool, so a write - from a tool's own
     * bug or from anything it calls - fails at the database. Not available
     * when a transaction is already open (tests run inside one).
     */
    private function _beginReadOnly(Varien_Db_Adapter_Interface $connection): bool
    {
        if (method_exists($connection, 'getTransactionLevel') && $connection->getTransactionLevel() > 0) {
            return false;
        }

        try {
            $connection->query('START TRANSACTION READ ONLY');

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

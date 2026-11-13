<?php
/**
 * Conversations and their messages, each belonging to the admin user who
 * had it. Nobody reads another person's conversation through the chat; the
 * audit log is where an administrator sees what was asked.
 */
class YSRTech_AdminAssistant_Model_Conversation
{
    /** Messages of history sent back to the model with a new question */
    public const HISTORY_MESSAGES = 40;

    /** A tool result from an earlier question is cut to this when sent again */
    public const OLD_TOOL_RESULT_CHARS = 3000;

    private function _db(): Varien_Db_Adapter_Interface
    {
        return Mage::getSingleton('core/resource')->getConnection('core_write');
    }

    private function _t(string $entity): string
    {
        return Mage::getSingleton('core/resource')->getTableName('ysrtech_adminassistant/' . $entity);
    }

    public function create(int $userId, string $title): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->_db()->insert($this->_t('conversation'), [
            'admin_user_id' => $userId,
            'title'         => mb_substr(trim(preg_replace('/\s+/', ' ', $title)), 0, 120),
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        return (int) $this->_db()->lastInsertId();
    }

    /**
     * The conversation, if it belongs to this user.
     */
    public function load(int $conversationId, int $userId): ?array
    {
        $db  = $this->_db();
        $row = $db->fetchRow($db->select()->from($this->_t('conversation'))
            ->where('conversation_id = ?', $conversationId)
            ->where('admin_user_id = ?', $userId));

        return $row ?: null;
    }

    public function listFor(int $userId, int $limit = 30): array
    {
        $db = $this->_db();

        return array_map(static fn ($r) => [
            'id'      => (int) $r['conversation_id'],
            'title'   => $r['title'],
            'updated' => YSRTech_AdminAssistant_Model_Tool_Abstract::local($r['updated_at']),
        ], $db->fetchAll($db->select()->from($this->_t('conversation'), ['conversation_id', 'title', 'updated_at'])
            ->where('admin_user_id = ?', $userId)
            ->order('updated_at DESC')->limit($limit)));
    }

    public function delete(int $conversationId, int $userId): bool
    {
        return (bool) $this->_db()->delete($this->_t('conversation'), [
            'conversation_id = ?' => $conversationId,
            'admin_user_id = ?'   => $userId,
        ]);
    }

    public function add(int $conversationId, string $role, ?string $content, ?array $toolData = null, ?array $page = null): int
    {
        $db  = $this->_db();
        $now = gmdate('Y-m-d H:i:s');
        $db->insert($this->_t('message'), [
            'conversation_id' => $conversationId,
            'role'            => $role,
            'content'         => $content,
            'tool_data'       => $toolData !== null ? json_encode($toolData, JSON_UNESCAPED_UNICODE) : null,
            'page_context'    => $page !== null ? json_encode($page, JSON_UNESCAPED_UNICODE) : null,
            'created_at'      => $now,
        ]);
        $id = (int) $db->lastInsertId();
        $db->update($this->_t('conversation'), ['updated_at' => $now], ['conversation_id = ?' => $conversationId]);

        return $id;
    }

    /**
     * The conversation in the chat-completions format, for the model.
     */
    public function history(int $conversationId): array
    {
        $db   = $this->_db();
        $rows = array_reverse($db->fetchAll($db->select()->from($this->_t('message'))
            ->where('conversation_id = ?', $conversationId)
            ->order('message_id DESC')->limit(self::HISTORY_MESSAGES)));

        // History must not open on a tool result or an assistant turn whose
        // tool calls were cut off: start at the first question in the window
        while ($rows && $rows[0]['role'] !== 'user') {
            array_shift($rows);
        }

        $out = [];

        foreach ($rows as $r) {
            $data = $r['tool_data'] ? json_decode($r['tool_data'], true) : null;

            if ($r['role'] === 'user') {
                $out[] = ['role' => 'user', 'content' => (string) $r['content']];
            } elseif ($r['role'] === 'assistant') {
                $message = ['role' => 'assistant', 'content' => (string) $r['content']];
                if (!empty($data['tool_calls'])) {
                    $message['tool_calls'] = array_map(static fn ($c) => [
                        'id'       => $c['id'],
                        'type'     => 'function',
                        'function' => ['name' => $c['name'], 'arguments' => $c['arguments'] !== '' ? $c['arguments'] : '{}'],
                    ], $data['tool_calls']);
                }
                $out[] = $message;
            } elseif ($r['role'] === 'tool') {
                $content = (string) $r['content'];
                if (mb_strlen($content) > self::OLD_TOOL_RESULT_CHARS) {
                    $content = mb_substr($content, 0, self::OLD_TOOL_RESULT_CHARS) . '… [earlier result shortened; look it up again if needed]';
                }
                $out[] = ['role' => 'tool', 'tool_call_id' => (string) ($data['id'] ?? ''), 'content' => $content];
            }
        }

        return $out;
    }

    /**
     * The conversation as the panel shows it: questions, answers, and which
     * look-ups were made.
     */
    public function transcript(int $conversationId): array
    {
        $db  = $this->_db();
        $out = [];

        foreach ($db->fetchAll($db->select()->from($this->_t('message'))->where('conversation_id = ?', $conversationId)->order('message_id ASC')) as $r) {
            $data = $r['tool_data'] ? json_decode($r['tool_data'], true) : null;

            if ($r['role'] === 'user') {
                $out[] = ['role' => 'user', 'text' => (string) $r['content']];
            } elseif ($r['role'] === 'assistant') {
                // In the order the panel showed them live: what the model
                // said first, then what it looked up
                if (trim((string) $r['content']) !== '') {
                    $out[] = ['role' => 'assistant', 'text' => (string) $r['content']];
                }
                foreach ((array) ($data['tool_calls'] ?? []) as $call) {
                    $out[] = ['role' => 'tool', 'name' => $call['name'], 'summary' => $data['summaries'][$call['id']] ?? ''];
                }
            }
        }

        return $out;
    }
}

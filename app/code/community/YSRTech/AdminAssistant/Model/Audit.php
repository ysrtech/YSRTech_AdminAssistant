<?php
/**
 * The audit log: who asked what, every look-up the assistant made for them
 * with its input and how many rows came back, and every request to the
 * provider with its tokens and cost.
 *
 * Used as a singleton for one request: question() opens the record that the
 * tool and model lines after it point back to.
 */
class YSRTech_AdminAssistant_Model_Audit
{
    private ?int $_questionId = null;

    private ?int $_conversationId = null;

    private ?int $_userId = null;

    private string $_username = '';

    public function begin(?Mage_Admin_Model_User $user, ?int $conversationId): self
    {
        $this->_userId         = $user ? (int) $user->getId() : null;
        $this->_username       = $user ? (string) $user->getUsername() : (PHP_SAPI === 'cli' ? 'cli' : '');
        $this->_conversationId = $conversationId;
        $this->_questionId     = null;

        return $this;
    }

    public function question(string $question): int
    {
        $this->_questionId = $this->_insert([
            'kind'     => 'question',
            'question' => mb_substr($question, 0, 20000),
        ]);

        return $this->_questionId;
    }

    public function tool(string $tool, array $input, ?int $rows, bool $ok, ?string $error, int $durationMs): void
    {
        $this->_insert([
            'kind'          => 'tool',
            'tool'          => mb_substr($tool, 0, 64),
            'input'         => mb_substr((string) json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 20000),
            'rows_returned' => $rows,
            'ok'            => $ok ? 1 : 0,
            'error'         => $error !== null ? mb_substr($error, 0, 255) : null,
            'duration_ms'   => $durationMs,
        ]);
    }

    public function model(string $model, bool $ok, int $in, int $out, ?float $cost, int $durationMs, ?string $error): void
    {
        $this->_insert([
            'kind'              => 'model',
            'model'             => mb_substr($model, 0, 120),
            'ok'                => $ok ? 1 : 0,
            'prompt_tokens'     => $in,
            'completion_tokens' => $out,
            'cost_usd'          => $cost,
            'duration_ms'       => $durationMs,
            // The provider's error text can quote the request; keep it short
            'error'             => $error !== null ? mb_substr($error, 0, 255) : null,
        ]);
    }

    private function _insert(array $row): ?int
    {
        try {
            $resource = Mage::getSingleton('core/resource');
            $write    = $resource->getConnection('core_write');
            $write->insert($resource->getTableName('ysrtech_adminassistant/audit'), $row + [
                'admin_user_id'   => $this->_userId,
                'username'        => mb_substr($this->_username, 0, 64),
                'conversation_id' => $this->_conversationId,
                'question_id'     => $this->_questionId,
                'created_at'      => gmdate('Y-m-d H:i:s'),
            ]);

            return (int) $write->lastInsertId();
        } catch (Throwable $e) {
            Mage::logException($e);

            return null;
        }
    }

    /**
     * Delete conversations and audit lines older than the setting. Daily, by cron.
     */
    public function cleanup(): void
    {
        $days     = max(1, (int) Mage::helper('ysrtech_adminassistant')->getConfig('behaviour/history_days'));
        $before   = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $resource = Mage::getSingleton('core/resource');
        $write    = $resource->getConnection('core_write');

        $write->delete($resource->getTableName('ysrtech_adminassistant/conversation'), ['updated_at < ?' => $before]);
        $write->delete($resource->getTableName('ysrtech_adminassistant/audit'), ['created_at < ?' => $before]);
    }
}

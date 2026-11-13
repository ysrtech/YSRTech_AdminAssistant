<?php
/**
 * The chat panel's endpoints. The answer to a question is streamed as
 * server-sent events over the POST that asked it.
 */
class YSRTech_AdminAssistant_Adminhtml_Ysrtech_AssistantController extends Mage_Adminhtml_Controller_Action
{
    /**
     * Someone who can use the chat, or - while it is switched off - the
     * person who could switch it on and is shown why it is off.
     */
    protected function _isAllowed(): bool
    {
        $session = Mage::getSingleton('admin/session');

        return $session->isAllowed(YSRTech_AdminAssistant_Helper_Data::ACL_USE)
            || $session->isAllowed(YSRTech_AdminAssistant_Helper_Data::ACL_CONFIG);
    }

    public function chatAction(): void
    {
        $h       = Mage::helper('ysrtech_adminassistant');
        $request = $this->getRequest();

        if (!$request->isPost() || !$this->_validateFormKey()) {
            $this->_json(['error' => $h->__('The page has expired. Reload it and ask again.')], 400);

            return;
        }

        $user = $h->adminUser();

        if ($h->isEnabled() && !$h->isAllowed(YSRTech_AdminAssistant_Helper_Data::ACL_USE)) {
            $this->_json(['error' => $h->__('Your role does not include the AI Assistant.')], 403);

            return;
        }

        $question       = (string) $request->getPost('question', '');
        $conversationId = (int) $request->getPost('conversation_id') ?: null;
        $page           = [
            'type' => preg_replace('/[^a-z_]/', '', (string) $request->getPost('page_type', '')),
            'id'   => (int) $request->getPost('page_id'),
            'page' => preg_replace('#[^a-z0-9_/]#i', '', (string) $request->getPost('page', '')),
        ];

        // The ACL is resolved for this request already; keep the session from
        // blocking the admin's other tabs while the answer streams
        Mage::getSingleton('admin/session')->getUser();
        session_write_close();

        @set_time_limit(300);
        @ini_set('zlib.output_compression', '0');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/event-stream; charset=UTF-8');
        header('Cache-Control: no-cache, no-store');
        header('X-Accel-Buffering: no'); // nginx: pass each event on as it comes
        header('X-Content-Type-Options: nosniff');

        $emit = static function (string $event, array $data): void {
            echo 'event: ' . $event . "\n" . 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
            flush();
        };

        echo ':' . str_repeat(' ', 2048) . "\n\n"; // pushes past proxies that buffer the first kilobytes
        flush();

        Mage::getModel('ysrtech_adminassistant/agent')->ask($user, $conversationId, $question, $page, $emit);

        // Headers and body are already sent; letting the front controller
        // send its own response would only log "headers already sent"
        exit;
    }

    public function conversationsAction(): void
    {
        $user = Mage::helper('ysrtech_adminassistant')->adminUser();
        $this->_json(['conversations' => Mage::getModel('ysrtech_adminassistant/conversation')->listFor((int) $user->getId())]);
    }

    public function conversationAction(): void
    {
        $user  = Mage::helper('ysrtech_adminassistant')->adminUser();
        $store = Mage::getModel('ysrtech_adminassistant/conversation');
        $id    = (int) $this->getRequest()->getParam('id');

        if (!$store->load($id, (int) $user->getId())) {
            $this->_json(['error' => 'Not found'], 404);

            return;
        }

        $this->_json(['id' => $id, 'messages' => $store->transcript($id)]);
    }

    public function deleteAction(): void
    {
        if (!$this->getRequest()->isPost() || !$this->_validateFormKey()) {
            $this->_json(['error' => 'Bad request'], 400);

            return;
        }

        $user = Mage::helper('ysrtech_adminassistant')->adminUser();
        $ok   = Mage::getModel('ysrtech_adminassistant/conversation')->delete((int) $this->getRequest()->getPost('id'), (int) $user->getId());
        $this->_json(['ok' => $ok]);
    }

    private function _json(array $data, int $status = 200): void
    {
        $this->getResponse()
            ->setHttpResponseCode($status)
            ->setHeader('Content-Type', 'application/json', true)
            ->setBody(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

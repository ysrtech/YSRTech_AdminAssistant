<?php
/**
 * The buttons on the settings page: test the AI connection, check the
 * report query's database user.
 */
class YSRTech_AdminAssistant_Adminhtml_Ysrtech_AssistantsettingsController extends Mage_Adminhtml_Controller_Action
{
    protected function _isAllowed(): bool
    {
        return Mage::getSingleton('admin/session')->isAllowed(YSRTech_AdminAssistant_Helper_Data::ACL_CONFIG);
    }

    public function testAction(): void
    {
        if (!$this->getRequest()->isPost() || !$this->_validateFormKey()) {
            $this->_json(['ok' => false, 'lines' => [['level' => 'error', 'text' => 'Reload the page and try again.']]]);

            return;
        }

        $h = Mage::helper('ysrtech_adminassistant');

        if (!$h->isEnabled()) {
            $this->_json(['ok' => false, 'lines' => [['level' => 'error', 'text' => $h->__('Turn the assistant on and save first: the test sends a request to the provider, which the switch forbids while off.')]]]);

            return;
        }

        Mage::getSingleton('ysrtech_adminassistant/audit')->begin($h->adminUser(), null);
        $result = Mage::getModel('ysrtech_adminassistant/ai_client')->test();
        $this->_json(['ok' => $result['ok'], 'lines' => [['level' => $result['ok'] ? 'ok' : 'error', 'text' => $result['message']]]]);
    }

    public function reportcheckAction(): void
    {
        if (!$this->getRequest()->isPost() || !$this->_validateFormKey()) {
            $this->_json(['ok' => false, 'lines' => [['level' => 'error', 'text' => 'Reload the page and try again.']]]);

            return;
        }

        $this->_json(Mage::getModel('ysrtech_adminassistant/report_query')->check());
    }

    private function _json(array $data): void
    {
        $this->getResponse()->setHeader('Content-Type', 'application/json', true)->setBody(json_encode($data));
    }
}

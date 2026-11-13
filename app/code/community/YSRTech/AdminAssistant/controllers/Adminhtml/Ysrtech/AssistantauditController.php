<?php
/**
 * System > AI Assistant Audit Log: every question, who asked it, and every
 * look-up made to answer it.
 */
class YSRTech_AdminAssistant_Adminhtml_Ysrtech_AssistantauditController extends Mage_Adminhtml_Controller_Action
{
    protected function _isAllowed(): bool
    {
        return Mage::getSingleton('admin/session')->isAllowed('system/ysrtech_adminassistant_audit');
    }

    public function indexAction(): void
    {
        $this->loadLayout();
        $this->_setActiveMenu('system/ysrtech_adminassistant_audit');
        $this->_title($this->__('System'))->_title($this->__('AI Assistant Audit Log'));
        $this->renderLayout();
    }
}

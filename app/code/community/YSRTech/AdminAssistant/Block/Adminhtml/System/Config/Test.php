<?php
class YSRTech_AdminAssistant_Block_Adminhtml_System_Config_Test extends YSRTech_AdminAssistant_Block_Adminhtml_System_Config_Button
{
    protected function _route(): string
    {
        return 'adminhtml/ysrtech_assistantsettings/test';
    }

    protected function _label(): string
    {
        return $this->__('Test Connection');
    }
}

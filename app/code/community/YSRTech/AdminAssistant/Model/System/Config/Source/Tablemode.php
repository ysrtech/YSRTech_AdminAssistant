<?php
class YSRTech_AdminAssistant_Model_System_Config_Source_Tablemode
{
    public function toOptionArray(): array
    {
        $h = Mage::helper('ysrtech_adminassistant');

        return [
            ['value' => 'recommended', 'label' => $h->__('Recommended tables (orders, customers, catalogue, campaigns)')],
            ['value' => 'custom', 'label' => $h->__('Choose tables')],
        ];
    }
}

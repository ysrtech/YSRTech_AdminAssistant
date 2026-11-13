<?php
class YSRTech_AdminAssistant_Model_System_Config_Source_Connection
{
    public function toOptionArray(): array
    {
        $h = Mage::helper('ysrtech_adminassistant');

        return [
            ['value' => 'emailcampaigns', 'label' => $h->__('Use Email Campaigns\' AI key and model')],
            ['value' => 'own', 'label' => $h->__('Use the key and model below')],
        ];
    }
}

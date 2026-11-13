<?php
/**
 * The statements that create the report query's user, table by table, to
 * copy into the database console.
 */
class YSRTech_AdminAssistant_Block_Adminhtml_System_Config_Grants extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element)
    {
        $user = trim((string) Mage::helper('ysrtech_adminassistant')->getConfig('report/username')) ?: 'store_ai_readonly';
        $sql  = Mage::getModel('ysrtech_adminassistant/report_query')->grantStatements($user);

        return '<textarea readonly rows="8" style="width:100%;font:12px/1.4 monospace;white-space:pre" onclick="this.select()">'
            . htmlspecialchars($sql, ENT_QUOTES, 'UTF-8') . '</textarea>'
            . '<p class="note"><span>' . $this->__('Run these as a database administrator, with a long password of your own in place of the one shown. Only SELECT is granted, and only on these tables. If your host only lets you grant a whole database, grant SELECT alone; the assistant still keeps to this list.') . '</span></p>';
    }
}

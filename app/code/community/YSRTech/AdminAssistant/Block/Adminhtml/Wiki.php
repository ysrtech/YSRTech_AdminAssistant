<?php
/**
 * The wiki's pages in the admin. One template, several modes: home, view,
 * search, edit and history.
 */
class YSRTech_AdminAssistant_Block_Adminhtml_Wiki extends Mage_Adminhtml_Block_Template
{
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('ysrtech_adminassistant/wiki.phtml');
    }

    public function wiki(): YSRTech_AdminAssistant_Model_Wiki
    {
        return Mage::getSingleton('ysrtech_adminassistant/wiki');
    }

    public function pageUrl(string $urlKey): string
    {
        return $this->getUrl('*/ysrtech_wiki/view', ['page' => $urlKey]);
    }

    public function local(?string $utc): string
    {
        return (string) YSRTech_AdminAssistant_Model_Tool_Abstract::local($utc);
    }

    public function assetUrl(string $file): string
    {
        $path = Mage::getBaseDir('skin') . DS . 'adminhtml' . DS . 'default' . DS . 'default' . DS . 'ysrtech' . DS . 'adminassistant' . DS . $file;

        return $this->getSkinUrl('ysrtech/adminassistant/' . $file) . (is_file($path) ? '?v=' . filemtime($path) : '');
    }
}

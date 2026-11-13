<?php
/**
 * The "Wiki" button in the admin header, to the left of the AI Assistant
 * button, for anyone whose role may read the wiki.
 */
class YSRTech_AdminAssistant_Block_Adminhtml_WikiLink extends Mage_Adminhtml_Block_Template
{
    protected function _toHtml()
    {
        $session = Mage::getSingleton('admin/session');

        if (!$session->getUser() || !$session->isAllowed(YSRTech_AdminAssistant_Model_Wiki::ACL_READ)) {
            return '';
        }

        $url   = $this->getUrl('adminhtml/ysrtech_wiki/index');
        $label = $this->__('Wiki');
        $on    = $this->getRequest()->getControllerName() === 'ysrtech_wiki';
        $css   = Mage::getSingleton('core/layout')->createBlock('ysrtech_adminassistant/adminhtml_wiki')->assetUrl('wiki.css');

        // Placed by script: the header is the theme's, and the AI button
        // takes the search box's place, so the link goes just before that spot
        return '<link rel="stylesheet" href="' . $this->escapeHtml($css) . '" />'
            . '<script>(function(){var h=document.querySelector(".header-right");if(!h||document.getElementById("ysrwk-link")){return;}'
            . 'var a=document.createElement("a");a.id="ysrwk-link";a.className="ysrwk-launch' . ($on ? ' on' : '') . '";a.href=' . json_encode($url) . ';'
            . 'a.innerHTML=\'<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" d="M4 4.5A1.5 1.5 0 0 1 5.5 3H20v15H5.5A1.5 1.5 0 0 0 4 19.5zM4 19.5A1.5 1.5 0 0 0 5.5 21H20v-3"/></svg><span>\'+' . json_encode($this->escapeHtml($label)) . '+\'</span>\';'
            . 'var wrap=document.createElement("fieldset");wrap.className="ysrwk-slot";wrap.appendChild(a);'
            . 'var s=document.getElementById("global_search"),spot=h.querySelector(".ysraa-launch-slot")||(s?(s.closest("fieldset")||s.parentNode):null);'
            . 'spot&&spot.parentNode===h?h.insertBefore(wrap,spot):h.appendChild(wrap);})();</script>';
    }
}

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
            . 'a.title=' . json_encode($label) . ';a.setAttribute("aria-label",' . json_encode($label) . ');'
            . 'a.innerHTML=\'<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><circle cx="12" cy="12" r="9.5" fill="none" stroke="currentColor" stroke-width="2"/>'
            . '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M9.4 9.3a2.7 2.7 0 0 1 5.2 1c0 1.8-2.6 2.4-2.6 4"/><circle cx="12" cy="17.4" r="1.2" fill="currentColor"/></svg>\';'
            . 'var wrap=document.createElement("fieldset");wrap.className="ysrwk-slot";wrap.appendChild(a);'
            . 'var s=document.getElementById("global_search"),spot=h.querySelector(".ysraa-launch-slot")||(s?(s.closest("fieldset")||s.parentNode):null);'
            . 'spot&&spot.parentNode===h?h.insertBefore(wrap,spot):h.appendChild(wrap);})();</script>';
    }
}

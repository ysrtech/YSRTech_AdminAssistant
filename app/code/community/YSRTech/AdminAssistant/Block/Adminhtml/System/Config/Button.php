<?php
/**
 * A settings-page button that posts to an endpoint and lists what came back.
 */
abstract class YSRTech_AdminAssistant_Block_Adminhtml_System_Config_Button extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    abstract protected function _route(): string;

    abstract protected function _label(): string;

    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element)
    {
        $id   = 'ysraa_' . md5($this->_route());
        $url  = $this->getUrl($this->_route());
        $key  = Mage::getSingleton('core/session')->getFormKey();
        $e    = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $busy = $this->__('Checking…');

        return '<button type="button" class="scalable" id="' . $id . '_btn"><span><span><span>' . $e($this->_label()) . '</span></span></span></button>'
            . '<div id="' . $id . '_out" style="margin-top:8px"></div>'
            . '<script>(function(){var b=document.getElementById(' . json_encode($id . '_btn') . '),o=document.getElementById(' . json_encode($id . '_out') . ');'
            . 'b.addEventListener("click",function(){o.textContent=' . json_encode($busy) . ';var f=new FormData();f.append("form_key",' . json_encode($key) . ');'
            . 'fetch(' . json_encode($url) . ',{method:"POST",body:f,credentials:"same-origin"}).then(function(r){return r.json()}).then(function(d){o.innerHTML="";(d.lines||[]).forEach(function(l){var p=document.createElement("div");'
            . 'p.style.color=l.level==="ok"?"#16a34a":(l.level==="warn"?"#b45309":"#dc2626");p.textContent=(l.level==="ok"?"✓ ":(l.level==="warn"?"! ":"✗ "))+l.text;o.appendChild(p)})})'
            . '.catch(function(){o.textContent="The check could not run. Reload the page and try again."})})})();</script>';
    }
}

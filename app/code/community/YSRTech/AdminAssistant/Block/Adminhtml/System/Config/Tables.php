<?php
/**
 * The table list as tick boxes, grouped by area, with a filter, a "chosen
 * only" switch and a count. Tick boxes rather than a multi-select: with
 * hundreds of tables, one plain click in a multi-select clears every other
 * choice. Starts with the recommended tables ticked until a choice is saved.
 */
class YSRTech_AdminAssistant_Block_Adminhtml_System_Config_Tables extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element)
    {
        $stored = trim((string) Mage::helper('ysrtech_adminassistant')->getConfig('report/tables'));
        $chosen = $stored === ''
            ? Mage::getModel('ysrtech_adminassistant/report_guard')->recommendedTables()
            : array_map('trim', explode(',', $stored));
        $chosen = array_fill_keys(array_map('strtolower', $chosen), true);

        $id   = $element->getHtmlId();
        $name = preg_replace("/\[\]$/", "", (string) $element->getName()); // the multi-select element adds [] itself
        $e    = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        // An empty value is always sent, so unticking everything saves as nothing chosen
        $html = '<div class="ysraa-tables" id="' . $e($id) . '">'
            . '<input type="hidden" name="' . $e($name) . '[]" value="" />'
            . '<div class="ysraa-tables-bar">'
            . '<input type="search" class="input-text" data-filter placeholder="' . $e($this->__('Filter tables')) . '" />'
            . '<label><input type="checkbox" data-only /> ' . $e($this->__('Chosen only')) . '</label>'
            . '<span data-count></span></div><div class="ysraa-tables-list">';

        foreach (Mage::getModel('ysrtech_adminassistant/system_config_source_tables')->toOptionArray() as $group) {
            $html .= '<fieldset data-group><legend>' . $e($group['label']) . '</legend>';
            foreach ($group['value'] as $option) {
                $html .= '<label data-table="' . $e($option['value']) . '"><input type="checkbox" name="' . $e($name) . '[]" value="' . $e($option['value']) . '"'
                    . (isset($chosen[$option['value']]) ? ' checked' : '') . ' /> ' . $e($option['label']) . '</label>';
            }
            $html .= '</fieldset>';
        }

        $html .= '</div></div>'
            . '<style>'
            . '.ysraa-tables{border:1px solid #d4d4d8;border-radius:8px;background:#fff}'
            . '.ysraa-tables-bar{display:flex;gap:12px;align-items:center;padding:8px;border-bottom:1px solid #e4e4e7}'
            . '.ysraa-tables-bar input[type=search]{flex:1;width:auto!important}.ysraa-tables-bar label{white-space:nowrap}'
            . '.ysraa-tables-bar [data-count]{color:#52525b;white-space:nowrap}'
            . '.ysraa-tables-list{max-height:340px;overflow:auto;padding:4px 10px 10px}'
            . '.ysraa-tables-list fieldset{border:0;margin:8px 0 0;padding:0}'
            . '.ysraa-tables-list legend{display:block;font-weight:bold;color:#18181b;margin:0 0 2px}'
            . '.ysraa-tables-list label{display:block;padding:1px 0;font-family:monospace;font-size:12px}'
            . '</style>'
            . '<script>(function(){var box=document.getElementById(' . json_encode($id) . ');'
            . 'var f=box.querySelector("[data-filter]"),only=box.querySelector("[data-only]"),count=box.querySelector("[data-count]");'
            . 'function apply(){var q=f.value.toLowerCase(),n=0;'
            . 'box.querySelectorAll("label[data-table]").forEach(function(l){var c=l.querySelector("input").checked;if(c){n++;}'
            . 'l.hidden=(q!==""&&l.dataset.table.indexOf(q)===-1)||(only.checked&&!c);});'
            . 'box.querySelectorAll("fieldset[data-group]").forEach(function(g){g.hidden=!g.querySelector("label[data-table]:not([hidden])");});'
            . 'count.textContent=n+' . json_encode(' ' . $this->__('chosen')) . ';}'
            . 'f.addEventListener("input",apply);only.addEventListener("change",apply);box.addEventListener("change",function(e){if(e.target.name){apply();}});apply();})();</script>';

        return $html;
    }
}

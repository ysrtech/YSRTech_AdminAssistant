<?php
/**
 * Saves the chosen tables, dropping any that may never be read, however the
 * request was made.
 */
class YSRTech_AdminAssistant_Model_System_Config_Backend_Tables extends Mage_Core_Model_Config_Data
{
    protected function _beforeSave()
    {
        $value  = $this->getValue();
        $tables = is_array($value) ? $value : explode(',', (string) $value);
        $tables = array_values(array_unique(array_filter(array_map(static fn ($t) => strtolower(trim((string) $t)), $tables),
            static fn ($t) => $t !== '' && preg_match('/^[a-z0-9_$]+$/', $t) && !YSRTech_AdminAssistant_Model_Report_Guard::isForbiddenTable($t))));
        $this->setValue(implode(',', $tables));

        return parent::_beforeSave();
    }
}

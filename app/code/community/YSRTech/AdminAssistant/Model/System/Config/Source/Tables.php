<?php
/**
 * Every table in the store's database that the report query may be given,
 * grouped by the first word of its name. Forbidden tables are not offered.
 */
class YSRTech_AdminAssistant_Model_System_Config_Source_Tables
{
    public function toOptionArray(): array
    {
        $prefix = strtolower((string) Mage::getConfig()->getTablePrefix());
        $groups = [];

        foreach (Mage::getSingleton('core/resource')->getConnection('core_read')->listTables() as $table) {
            $table = strtolower($table);

            if (YSRTech_AdminAssistant_Model_Report_Guard::isForbiddenTable($table)) {
                continue;
            }

            $bare  = $prefix !== '' && strpos($table, $prefix) === 0 ? substr($table, strlen($prefix)) : $table;
            $group = strtok($bare, '_') ?: $bare;
            $groups[$group][] = ['value' => $table, 'label' => $table];
        }

        ksort($groups);

        $out = [];
        foreach ($groups as $group => $options) {
            usort($options, static fn ($a, $b) => strcmp($a['value'], $b['value']));
            $out[] = ['label' => $group, 'value' => $options];
        }

        return $out;
    }
}

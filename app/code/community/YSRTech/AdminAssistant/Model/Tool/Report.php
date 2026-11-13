<?php
/**
 * The guarded report query: for questions no other tool answers.
 */
class YSRTech_AdminAssistant_Model_Tool_Report extends YSRTech_AdminAssistant_Model_Tool_Abstract
{
    protected string $_acl = 'report';

    public function available(): bool
    {
        return Mage::getSingleton('ysrtech_adminassistant/report_query')->configured();
    }

    public function definitions(): array
    {
        return [
            'report_schema' => [
                'description' => 'The tables the report query may read, with their columns, and the attribute ids needed to read product, category and address values (EAV). Call this before writing a report_query.',
                'parameters'  => ['properties' => [
                    'tables' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Only these tables (exact names). Omit for the list of names alone.'],
                ]],
            ],
            'report_query' => [
                'description' => 'Run one read-only SELECT (MySQL/MariaDB) over the allowed tables, for questions no other tool answers. Rules: a single SELECT; no WITH, comments, variables, double-quoted strings or backslashes; tables only from report_schema. Dates are stored in UTC. Rows are capped; aggregate in SQL rather than fetching many rows. Prefer the other tools whenever one fits.',
                'parameters'  => ['properties' => [
                    'sql'     => ['type' => 'string'],
                    'purpose' => ['type' => 'string', 'description' => 'In a few words, what the query answers (shown to the person and kept in the audit log)'],
                ], 'required' => ['sql', 'purpose']],
            ],
        ];
    }

    public function run(string $name, array $args): array
    {
        if ($name === 'report_schema') {
            return $this->_schema($args);
        }

        if ($name === 'report_query') {
            $result = Mage::getSingleton('ysrtech_adminassistant/report_query')->run((string) ($args['sql'] ?? ''));

            return ['rows' => count($result['rows']), 'data' => $result + [
                'timezone'      => 'Datetime columns are UTC; the store\'s timezone is ' . self::timezone()->getName() . '.',
                'about'          => $result['truncated'] ? 'More rows matched than are shown; say so, or aggregate in SQL.' : null,
            ]];
        }

        Mage::throwException('Unknown tool');
    }

    private function _schema(array $args): array
    {
        $guard   = Mage::getSingleton('ysrtech_adminassistant/report_guard');
        $allowed = $guard->allowedTables();
        $db      = $this->_read();
        $exists  = array_map('strtolower', $db->listTables());
        $allowed = array_values(array_intersect($allowed, $exists));
        $wanted  = array_values(array_intersect(array_map('strtolower', (array) ($args['tables'] ?? [])), $allowed));

        $data = [
            'tables' => $allowed,
            'store_timezone' => self::timezone()->getName(),
            'hints'  => [
                'Order revenue: sales_flat_order.base_grand_total, leaving out state = \'canceled\'. Dates are UTC.',
                'Product values are in catalog_product_entity_<type> by attribute_id and store_id (0 = default). Use the attribute ids below.',
                'Registered customers\' names are not readable here; use customer_firstname/customer_lastname on sales_flat_order, or the customer tools.',
                'Order lines of configurable products appear twice (parent and child); filter parent_item_id IS NULL for one line per product.',
            ],
            'attribute_ids' => $this->_attributeIds(),
        ];

        if ($wanted) {
            $data['columns'] = [];
            foreach ($wanted as $table) {
                $data['columns'][$table] = array_values(array_filter(
                    array_map(static fn ($c) => $c['COLUMN_NAME'] . ' ' . strtolower($c['DATA_TYPE']), $db->describeTable($table)),
                    static fn ($c) => !YSRTech_AdminAssistant_Model_Report_Guard::isDeniedColumn(strtok($c, ' '))
                ));
            }
        }

        return ['rows' => count($wanted) ?: count($allowed), 'data' => $data];
    }

    private function _attributeIds(): array
    {
        $db   = $this->_read();
        $rows = $db->fetchAll($db->select()
            ->from(['a' => $this->_table('eav/attribute')], ['attribute_code', 'attribute_id', 'backend_type'])
            ->join(['t' => $this->_table('eav/entity_type')], 't.entity_type_id = a.entity_type_id', ['entity_type_code'])
            ->where('t.entity_type_code IN (?)', ['catalog_product', 'catalog_category', 'customer_address'])
            ->where('a.attribute_code IN (?)', ['name', 'price', 'special_price', 'cost', 'status', 'visibility', 'url_key', 'manufacturer', 'color', 'size', 'is_active', 'weight', 'firstname', 'lastname', 'city', 'postcode', 'country_id', 'telephone']));

        $out = [];
        foreach ($rows as $r) {
            $out[$r['entity_type_code']][$r['attribute_code']] = (int) $r['attribute_id'] . ' (' . $r['backend_type'] . ')';
        }

        return $out;
    }
}

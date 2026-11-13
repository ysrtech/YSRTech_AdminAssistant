<?php
/**
 * Sales figures: revenue, orders, average order value, by period and broken
 * down, and one period against another.
 *
 * Revenue here is the base-currency grand total of orders that were not
 * canceled; net revenue subtracts what was refunded. Both are stated in the
 * result so the answer can say which it used.
 */
class YSRTech_AdminAssistant_Model_Tool_Sales extends YSRTech_AdminAssistant_Model_Tool_Abstract
{
    protected string $_acl = 'sales';

    public const GROUPS = ['none', 'day', 'week', 'month', 'year', 'store', 'status', 'payment_method', 'shipping_method', 'country', 'customer_group', 'hour_of_day', 'day_of_week'];

    public function definitions(): array
    {
        return [
            'sales_summary' => [
                'description' => 'Revenue, net revenue (after refunds), orders, units, average order value, discounts, shipping and tax for a period, optionally broken down by day, week, month, store, status, payment method, shipping method, billing country, customer group, hour or weekday. Canceled orders are left out.',
                'parameters'  => ['properties' => self::periodSchema() + [
                    'group_by' => ['type' => 'string', 'enum' => self::GROUPS],
                    'store_id' => ['type' => 'integer'],
                ]],
            ],
            'sales_compare' => [
                'description' => 'One period against another: the same figures for each and the change in percent. Defaults to the chosen period against the one just before it, of equal length.',
                'parameters'  => ['properties' => [
                    'period'        => self::periodSchema()['period'],
                    'from'          => self::periodSchema()['from'],
                    'to'            => self::periodSchema()['to'],
                    'compare_from'  => ['type' => 'string', 'description' => 'First day of the other period, YYYY-MM-DD. Omit for the period just before.'],
                    'compare_to'    => ['type' => 'string', 'description' => 'Last day of the other period, YYYY-MM-DD'],
                    'store_id'      => ['type' => 'integer'],
                ]],
            ],
        ];
    }

    public function run(string $name, array $args): array
    {
        if ($name === 'sales_summary') {
            return $this->_summary($args);
        }

        if ($name === 'sales_compare') {
            return $this->_compare($args);
        }

        Mage::throwException('Unknown tool');
    }

    private function _columns(): array
    {
        return [
            'orders'      => new Zend_Db_Expr('COUNT(*)'),
            'revenue'     => new Zend_Db_Expr('COALESCE(SUM(o.base_grand_total), 0)'),
            'refunded'    => new Zend_Db_Expr('COALESCE(SUM(o.base_total_refunded), 0)'),
            'units'       => new Zend_Db_Expr('COALESCE(SUM(o.total_qty_ordered), 0)'),
            'discounts'   => new Zend_Db_Expr('COALESCE(SUM(o.base_discount_amount), 0)'),
            'shipping'    => new Zend_Db_Expr('COALESCE(SUM(o.base_shipping_amount), 0)'),
            'tax'         => new Zend_Db_Expr('COALESCE(SUM(o.base_tax_amount), 0)'),
            'customers'   => new Zend_Db_Expr('COUNT(DISTINCT o.customer_email)'),
        ];
    }

    private function _base(array $range, array $args): Varien_Db_Select
    {
        $select = $this->_read()->select()
            ->from(['o' => $this->_table('sales/order')], [])
            ->where('o.state != ?', 'canceled');
        $this->_applyRange($select, 'o.created_at', $range);

        if (isset($args['store_id'])) {
            $select->where('o.store_id = ?', (int) $args['store_id']);
        }

        return $select;
    }

    private function _shape(array $r): array
    {
        $orders = (int) $r['orders'];

        return [
            'orders'              => $orders,
            'revenue'             => $this->_money($r['revenue']),
            'net_revenue'         => $this->_money($r['revenue'] - $r['refunded']),
            'refunded'            => $this->_money($r['refunded']),
            'average_order_value' => $orders ? $this->_money($r['revenue'] / $orders) : 0,
            'units'               => (float) $r['units'],
            'discounts'           => $this->_money(abs((float) $r['discounts'])),
            'shipping'            => $this->_money($r['shipping']),
            'tax'                 => $this->_money($r['tax']),
            'distinct_customers'  => (int) $r['customers'],
        ];
    }

    public function figures(array $range, array $args): array
    {
        return $this->_shape($this->_read()->fetchRow($this->_base($range, $args)->columns($this->_columns())));
    }

    private function _summary(array $args): array
    {
        $range = self::range($args, 'last_30_days');
        $group = in_array($args['group_by'] ?? 'none', self::GROUPS, true) ? ($args['group_by'] ?? 'none') : 'none';
        $data  = [
            'period'      => $range,
            'currency'    => Mage::app()->getBaseCurrencyCode(),
            'definition'  => 'revenue = grand total of orders not canceled, in base currency; net_revenue subtracts refunds',
            'totals'      => $this->figures($range, $args),
        ];

        if ($group === 'none') {
            return ['rows' => 1, 'data' => $data];
        }

        $select = $this->_base($range, $args);
        $key    = null;

        switch ($group) {
            case 'day':         $key = $this->_localDayExpr('o.created_at'); break;
            case 'week':        $key = $this->_localDayExpr('o.created_at', '%x-W%v'); break;
            case 'month':       $key = $this->_localDayExpr('o.created_at', '%Y-%m'); break;
            case 'year':        $key = $this->_localDayExpr('o.created_at', '%Y'); break;
            case 'hour_of_day': $key = $this->_localDayExpr('o.created_at', '%H'); break;
            case 'day_of_week': $key = $this->_localDayExpr('o.created_at', '%w %W'); break;
            case 'store':       $key = new Zend_Db_Expr('o.store_id'); break;
            case 'status':      $key = new Zend_Db_Expr('o.status'); break;
            case 'shipping_method': $key = new Zend_Db_Expr('o.shipping_description'); break;
            case 'customer_group':
                $select->joinLeft(['g' => $this->_table('customer/customer_group')], 'g.customer_group_id = o.customer_group_id', []);
                $key = new Zend_Db_Expr('g.customer_group_code');
                break;
            case 'payment_method':
                // The method code only; nothing else from the payment table
                $select->joinLeft(['p' => $this->_table('sales/order_payment')], 'p.parent_id = o.entity_id', []);
                $key = new Zend_Db_Expr('p.method');
                break;
            case 'country':
                $select->joinLeft(['a' => $this->_table('sales/order_address')], "a.parent_id = o.entity_id AND a.address_type = 'billing'", []);
                $key = new Zend_Db_Expr('a.country_id');
                break;
        }

        $select->columns(['group' => $key] + $this->_columns())->group($key)->order('group ASC')->limit(400);
        $rows = $this->_read()->fetchAll($select);

        $data['group_by'] = $group;
        $data['groups']   = array_map(fn ($r) => ['group' => $r['group']] + $this->_shape($r), $rows);

        return ['rows' => count($rows), 'data' => $data];
    }

    private function _compare(array $args): array
    {
        $a  = self::range($args, 'last_7_days');

        if ($a['from_utc'] === null) {
            Mage::throwException('Comparing needs a period with a start.');
        }

        if (!empty($args['compare_from'])) {
            $b = self::range(['from' => $args['compare_from'], 'to' => $args['compare_to'] ?? null]);
        } else {
            $tz    = self::timezone();
            $first = new DateTimeImmutable($a['first_day'], $tz);
            $last  = new DateTimeImmutable($a['last_day'], $tz);
            $days  = (int) $first->diff($last)->days + 1;
            $b     = self::range([
                'from' => $first->modify('-' . $days . ' days')->format('Y-m-d'),
                'to'   => $first->modify('-1 day')->format('Y-m-d'),
            ]);
        }

        $fa = $this->figures($a, $args);
        $fb = $this->figures($b, $args);
        $change = [];

        foreach ($fa as $k => $v) {
            $change[$k] = (float) $fb[$k] != 0.0 ? round(((float) $v - (float) $fb[$k]) / abs((float) $fb[$k]) * 100, 1) . '%' : ($v ? 'new (was 0)' : 'no change (0)');
        }

        return ['rows' => 2, 'data' => [
            'currency'          => Mage::app()->getBaseCurrencyCode(),
            'period'            => $a,
            'compared_with'     => $b,
            'figures'           => $fa,
            'compared_figures'  => $fb,
            'change'            => $change,
        ]];
    }
}

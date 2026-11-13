<?php
/**
 * Customers: who they are, what they have bought, their group and whether
 * they are subscribed. Customer attributes are read by name; the password
 * hash and reset token are never selected.
 */
class YSRTech_AdminAssistant_Model_Tool_Customers extends YSRTech_AdminAssistant_Model_Tool_Abstract
{
    protected string $_acl = 'customers';

    /** Order states that do not count as a sale */
    public const NOT_SALES = ['canceled'];

    public function definitions(): array
    {
        return [
            'customer_search' => [
                'description' => 'Find registered customers by part of their name or email, by group, or by when they registered. Returns each with group, order count and lifetime value, and the total matched.',
                'parameters'  => ['properties' => self::periodSchema() + [
                    'query'    => ['type' => 'string', 'description' => 'Part of the name or email'],
                    'group_id' => ['type' => 'integer'],
                    'limit'    => ['type' => 'integer'],
                ]],
            ],
            'customer_details' => [
                'description' => 'One customer (registered, or a guest by email): profile, group, default addresses, newsletter status, order count, lifetime value, average order, first and last order, and their latest orders.',
                'parameters'  => ['properties' => [
                    'customer_id' => ['type' => 'integer'],
                    'email'       => ['type' => 'string'],
                ]],
            ],
            'top_customers' => [
                'description' => 'Customers ranked by revenue or by number of orders in a period, guests included (grouped by email). Canceled orders are left out. Use for "top 20 customers this year".',
                'parameters'  => ['properties' => self::periodSchema() + [
                    'by'    => ['type' => 'string', 'enum' => ['revenue', 'orders']],
                    'limit' => ['type' => 'integer'],
                ]],
            ],
            'customer_groups' => [
                'description' => 'The customer groups with how many customers each has.',
                'parameters'  => ['properties' => new stdClass()],
            ],
        ];
    }

    public function run(string $name, array $args): array
    {
        switch ($name) {
            case 'customer_search':  return $this->_search($args);
            case 'customer_details': return $this->_details($args);
            case 'top_customers':    return $this->_top($args);
            case 'customer_groups':  return $this->_groups();
        }

        Mage::throwException('Unknown tool');
    }

    private function _collection(): Mage_Customer_Model_Resource_Customer_Collection
    {
        /** @var Mage_Customer_Model_Resource_Customer_Collection $collection */
        $collection = Mage::getResourceModel('customer/customer_collection');
        $collection->addNameToSelect()->addAttributeToSelect(['email', 'group_id', 'created_at', 'website_id']);

        return $collection;
    }

    /**
     * @param  int[] $ids
     * @return array<int, array{orders:int, lifetime:float}>
     */
    private function _orderStats(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $db   = $this->_read();
        $rows = $db->fetchAll($db->select()
            ->from($this->_table('sales/order'), [
                'customer_id',
                'orders'   => new Zend_Db_Expr('COUNT(*)'),
                'lifetime' => new Zend_Db_Expr('COALESCE(SUM(base_grand_total - COALESCE(base_total_refunded, 0)), 0)'),
            ])
            ->where('customer_id IN (?)', $ids)
            ->where('state NOT IN (?)', self::NOT_SALES)
            ->group('customer_id'));

        $out = [];

        foreach ($rows as $r) {
            $out[(int) $r['customer_id']] = ['orders' => (int) $r['orders'], 'lifetime' => $this->_money($r['lifetime'])];
        }

        return $out;
    }

    private function _groupNames(): array
    {
        $db = $this->_read();

        return $db->fetchPairs($db->select()->from($this->_table('customer/customer_group'), ['customer_group_id', 'customer_group_code']));
    }

    private function _search(array $args): array
    {
        $collection = $this->_collection();

        if (!empty($args['query'])) {
            $like = $this->_like((string) $args['query']);
            $collection->addAttributeToFilter([
                ['attribute' => 'email', 'like' => $like],
                ['attribute' => 'firstname', 'like' => $like],
                ['attribute' => 'lastname', 'like' => $like],
            ]);
        }

        if (isset($args['group_id'])) {
            $collection->addAttributeToFilter('group_id', (int) $args['group_id']);
        }

        if (isset($args['period']) || isset($args['from']) || isset($args['to'])) {
            $range = self::range($args);
            if ($range['from_utc']) {
                $collection->addAttributeToFilter('created_at', ['gteq' => $range['from_utc']]);
            }
            $collection->addAttributeToFilter('created_at', ['lt' => $range['to_utc']]);
        }

        $matched = (int) $collection->getSize();
        $collection->setOrder('created_at', 'desc')->setPageSize($this->_limit($args))->setCurPage(1);

        $groups = $this->_groupNames();
        $stats  = $this->_orderStats(array_map('intval', $collection->getAllIds($this->_limit($args))));
        $list   = [];

        foreach ($collection as $c) {
            $id     = (int) $c->getId();
            $list[] = [
                'customer_id'    => $id,
                'customer_name'  => trim($c->getFirstname() . ' ' . $c->getLastname()),
                'email'          => $c->getEmail(),
                'group'          => $groups[$c->getGroupId()] ?? $c->getGroupId(),
                'registered'     => self::local($c->getCreatedAt()),
                'orders'         => $stats[$id]['orders'] ?? 0,
                'lifetime_value' => $stats[$id]['lifetime'] ?? 0,
            ];
        }

        return ['rows' => count($list), 'data' => ['matched' => $matched, 'currency' => Mage::app()->getBaseCurrencyCode(), 'customers' => $list]];
    }

    private function _details(array $args): array
    {
        $db       = $this->_read();
        $customer = null;

        if (!empty($args['customer_id'])) {
            $customer = $this->_collection()->addAttributeToFilter('entity_id', (int) $args['customer_id'])->getFirstItem();
        } elseif (!empty($args['email'])) {
            $customer = $this->_collection()->addAttributeToFilter('email', trim((string) $args['email']))->getFirstItem();
        } else {
            Mage::throwException('Give a customer_id or an email.');
        }

        $id    = $customer && $customer->getId() ? (int) $customer->getId() : null;
        $email = $id ? (string) $customer->getEmail() : trim((string) ($args['email'] ?? ''));

        if (!$id && $email === '') {
            Mage::throwException('No customer with that id.');
        }

        $who   = $id ? '(customer_id = ' . $id . ' OR customer_email = ' . $db->quote($email) . ')' : 'customer_email = ' . $db->quote($email);
        $stats = $db->fetchRow($db->select()
            ->from($this->_table('sales/order'), [
                'orders'   => new Zend_Db_Expr('COUNT(*)'),
                'lifetime' => new Zend_Db_Expr('COALESCE(SUM(base_grand_total - COALESCE(base_total_refunded, 0)), 0)'),
                'first'    => new Zend_Db_Expr('MIN(created_at)'),
                'last'     => new Zend_Db_Expr('MAX(created_at)'),
            ])
            ->where('state NOT IN (?)', self::NOT_SALES)
            ->where($who));

        $latest = $db->fetchAll($db->select()
            ->from($this->_table('sales/order'), ['increment_id', 'created_at', 'status', 'base_grand_total'])
            ->where($who)
            ->order('created_at DESC')->limit(10));

        if (!$id && !$latest) {
            Mage::throwException('No customer or guest order with that email.');
        }

        $subscriber = $db->fetchRow($db->select()
            ->from($this->_table('newsletter/subscriber'), ['subscriber_status', 'change_status_at'])
            ->where('subscriber_email = ?', $email)->limit(1));

        $statusNames = [1 => 'subscribed', 2 => 'not active', 3 => 'unsubscribed', 4 => 'awaiting confirmation'];
        $count       = (int) $stats['orders'];

        $data = [
            'registered'      => (bool) $id,
            'customer_id'     => $id,
            'admin_url'       => $id ? $this->_adminUrl('adminhtml/customer/edit', ['id' => $id]) : null,
            'customer_name'   => $id ? trim($customer->getFirstname() . ' ' . $customer->getLastname()) : null,
            'email'           => $email,
            'group'           => $id ? ($this->_groupNames()[$customer->getGroupId()] ?? $customer->getGroupId()) : 'NOT LOGGED IN (guest)',
            'registered_on'   => $id ? self::local($customer->getCreatedAt()) : null,
            'newsletter'      => $subscriber ? ($statusNames[(int) $subscriber['subscriber_status']] ?? 'unknown') : 'never subscribed',
            'newsletter_since'=> $subscriber ? self::local($subscriber['change_status_at']) : null,
            'orders'          => $count,
            'lifetime_value'  => $this->_money($stats['lifetime']),
            'average_order'   => $count ? $this->_money($stats['lifetime'] / $count) : 0,
            'currency'        => Mage::app()->getBaseCurrencyCode(),
            'first_order'     => self::local($stats['first']),
            'last_order'      => self::local($stats['last']),
            'latest_orders'   => array_map(fn ($r) => [
                'increment_id' => $r['increment_id'],
                'placed'       => self::local($r['created_at']),
                'status'       => $r['status'],
                'total'        => $this->_money($r['base_grand_total']),
            ], $latest),
        ];

        if ($id) {
            $address = Mage::getModel('customer/address');
            foreach (['billing' => $customer->getDefaultBilling(), 'shipping' => $customer->getDefaultShipping()] as $type => $addressId) {
                if ($addressId && $address->load($addressId)->getId()) {
                    $data['default_' . $type . '_address'] = [
                        'firstname' => $address->getFirstname(), 'lastname' => $address->getLastname(),
                        'company'   => $address->getCompany(), 'street' => implode(', ', (array) $address->getStreet()),
                        'city'      => $address->getCity(), 'region' => $address->getRegion(),
                        'postcode'  => $address->getPostcode(), 'country_id' => $address->getCountryId(),
                        'telephone' => $address->getTelephone(),
                    ];
                }
            }
        }

        return ['rows' => 1 + count($latest), 'data' => $data];
    }

    private function _top(array $args): array
    {
        $db    = $this->_read();
        $range = self::range($args, 'this_year');
        $by    = ($args['by'] ?? 'revenue') === 'orders' ? 'orders' : 'revenue';

        $select = $db->select()
            ->from($this->_table('sales/order'), [
                'email'    => 'customer_email',
                'customer_id' => new Zend_Db_Expr('MAX(customer_id)'),
                'customer_name' => new Zend_Db_Expr("MAX(CONCAT_WS(' ', customer_firstname, customer_lastname))"),
                'orders'   => new Zend_Db_Expr('COUNT(*)'),
                'revenue'  => new Zend_Db_Expr('SUM(base_grand_total - COALESCE(base_total_refunded, 0))'),
                'last'     => new Zend_Db_Expr('MAX(created_at)'),
            ])
            ->where('state NOT IN (?)', self::NOT_SALES)
            ->group('customer_email')
            ->order($by === 'orders' ? 'orders DESC' : 'revenue DESC')
            ->order($by === 'orders' ? 'revenue DESC' : 'orders DESC')
            ->limit($this->_limit($args));
        $this->_applyRange($select, 'created_at', $range);

        $rows = $db->fetchAll($select);

        return ['rows' => count($rows), 'data' => [
            'period'   => $range,
            'ranked_by'=> $by . ' (refunds subtracted, canceled orders left out)',
            'currency' => Mage::app()->getBaseCurrencyCode(),
            'customers'=> array_map(fn ($r, $i) => [
                'rank'          => $i + 1,
                'customer_name' => $r['customer_name'],
                'email'         => $r['email'],
                'customer_id'   => $r['customer_id'] ? (int) $r['customer_id'] : null,
                'guest'         => !$r['customer_id'],
                'orders'        => (int) $r['orders'],
                'revenue'       => $this->_money($r['revenue']),
                'last_order'    => self::local($r['last']),
            ], $rows, array_keys($rows)),
        ]];
    }

    private function _groups(): array
    {
        $db   = $this->_read();
        $rows = $db->fetchAll($db->select()
            ->from(['g' => $this->_table('customer/customer_group')], ['customer_group_id', 'customer_group_code'])
            ->joinLeft(['c' => $this->_table('customer/entity')], 'c.group_id = g.customer_group_id', ['customers' => new Zend_Db_Expr('COUNT(c.entity_id)')])
            ->group('g.customer_group_id'));

        return ['rows' => count($rows), 'data' => ['groups' => $rows]];
    }
}

<?php
/**
 * Orders, and what happened to them: status history, shipments, invoices,
 * credit memos. Columns are named one by one, so nothing from the payment
 * table beyond the method's title is ever read.
 */
class YSRTech_AdminAssistant_Model_Tool_Orders extends YSRTech_AdminAssistant_Model_Tool_Abstract
{
    protected string $_acl = 'orders';

    public function definitions(): array
    {
        return [
            'order_search' => [
                'description' => 'Find orders and count them. Filters combine. Returns the total number matched, totals for the match, and up to "limit" orders. Use for "how many orders today", "pending orders older than 3 days", "orders from jane@example.com", "orders containing SKU X".',
                'parameters'  => ['properties' => self::periodSchema() + [
                    'increment_id'   => ['type' => 'string', 'description' => 'Order number, or part of one'],
                    'customer_email' => ['type' => 'string', 'description' => 'Exact or partial email'],
                    'customer_name'  => ['type' => 'string', 'description' => 'Part of the billing name'],
                    'status'         => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Order status codes, e.g. pending, processing, complete, canceled, holded, closed'],
                    'store_id'       => ['type' => 'integer'],
                    'sku'            => ['type' => 'string', 'description' => 'Only orders with an item of this SKU (exact)'],
                    'min_total'      => ['type' => 'number'],
                    'max_total'      => ['type' => 'number'],
                    'shipped'        => ['type' => 'boolean', 'description' => 'true: has at least one shipment; false: none yet'],
                    'invoiced'       => ['type' => 'boolean', 'description' => 'true: has at least one invoice; false: none yet'],
                    'sort'           => ['type' => 'string', 'enum' => ['newest', 'oldest', 'total_desc', 'total_asc']],
                    'limit'          => ['type' => 'integer', 'description' => 'Orders to list, 1-100, default 20. Use 1 when only the count matters.'],
                ]],
            ],
            'order_details' => [
                'description' => 'Everything about one order: customer, addresses, items with ordered/invoiced/shipped/refunded quantities, totals, payment method name, shipping method, the full status history with comments, shipments with tracking numbers, invoices and credit memos, plus automatic observations about anything that looks wrong. Use for "what\'s wrong with order X" or "this order".',
                'parameters'  => ['properties' => [
                    'increment_id' => ['type' => 'string', 'description' => 'The order number customers see'],
                    'order_id'     => ['type' => 'integer', 'description' => 'The internal entity id, when the page gives it'],
                ]],
            ],
            'shipment_search' => [
                'description' => 'Shipments created in a period, with their orders and tracking numbers. Use for "how many orders shipped yesterday".',
                'parameters'  => ['properties' => self::periodSchema() + [
                    'carrier' => ['type' => 'string', 'description' => 'Carrier code or title, partial'],
                    'limit'   => ['type' => 'integer'],
                ]],
            ],
            'invoice_creditmemo_search' => [
                'description' => 'Invoices or credit memos (refunds) created in a period, with counts and totals.',
                'parameters'  => ['properties' => self::periodSchema() + [
                    'type'  => ['type' => 'string', 'enum' => ['invoice', 'creditmemo']],
                    'limit' => ['type' => 'integer'],
                ], 'required' => ['type']],
            ],
        ];
    }

    public function run(string $name, array $args): array
    {
        switch ($name) {
            case 'order_search':              return $this->_search($args);
            case 'order_details':             return $this->_details($args);
            case 'shipment_search':           return $this->_shipments($args);
            case 'invoice_creditmemo_search': return $this->_documents($args);
        }

        Mage::throwException('Unknown tool');
    }

    private function _search(array $args): array
    {
        $db     = $this->_read();
        $order  = $this->_table('sales/order');
        $select = $db->select()->from(['o' => $order], []);
        $hasPeriod = isset($args['period']) || isset($args['from']) || isset($args['to']);
        $range  = self::range($args, 'all_time');

        if ($hasPeriod) {
            $this->_applyRange($select, 'o.created_at', $range);
        }

        if (!empty($args['increment_id'])) {
            $select->where('o.increment_id LIKE ?', $this->_like((string) $args['increment_id']));
        }

        if (!empty($args['customer_email'])) {
            $select->where('o.customer_email LIKE ?', $this->_like((string) $args['customer_email']));
        }

        if (!empty($args['customer_name'])) {
            $select->where("CONCAT_WS(' ', o.customer_firstname, o.customer_lastname) LIKE ?", $this->_like((string) $args['customer_name']));
        }

        if (!empty($args['status'])) {
            $select->where('o.status IN (?)', array_map('strval', (array) $args['status']));
        }

        if (isset($args['store_id'])) {
            $select->where('o.store_id = ?', (int) $args['store_id']);
        }

        if (isset($args['min_total'])) {
            $select->where('o.base_grand_total >= ?', (float) $args['min_total']);
        }

        if (isset($args['max_total'])) {
            $select->where('o.base_grand_total <= ?', (float) $args['max_total']);
        }

        if (!empty($args['sku'])) {
            $select->where('EXISTS (SELECT 1 FROM ' . $this->_table('sales/order_item') . ' i WHERE i.order_id = o.entity_id AND i.sku = ?)', (string) $args['sku']);
        }

        foreach (['shipped' => 'sales/shipment', 'invoiced' => 'sales/invoice'] as $flag => $table) {
            if (isset($args[$flag])) {
                $exists = 'EXISTS (SELECT 1 FROM ' . $this->_table($table) . ' d WHERE d.order_id = o.entity_id)';
                $select->where($args[$flag] ? $exists : "NOT {$exists}");
            }
        }

        $totals = $db->fetchRow((clone $select)->columns([
            'orders'  => new Zend_Db_Expr('COUNT(*)'),
            'revenue' => new Zend_Db_Expr('COALESCE(SUM(o.base_grand_total), 0)'),
        ]));

        $sort = [
            'oldest'     => 'o.created_at ASC',
            'total_desc' => 'o.base_grand_total DESC',
            'total_asc'  => 'o.base_grand_total ASC',
        ][$args['sort'] ?? ''] ?? 'o.created_at DESC';

        $rows = $db->fetchAll($select->columns([
            'entity_id', 'increment_id', 'created_at', 'status', 'state', 'store_id',
            'customer_email', 'customer_firstname', 'customer_lastname', 'customer_is_guest',
            'base_grand_total', 'base_currency_code', 'grand_total', 'order_currency_code',
            'total_qty_ordered', 'shipping_description',
        ])->order($sort)->limit($this->_limit($args)));

        $orders = array_map(fn ($r) => [
            'increment_id'   => $r['increment_id'],
            'order_id'       => (int) $r['entity_id'],
            'placed'         => self::local($r['created_at']),
            'status'         => $r['status'],
            'state'          => $r['state'],
            'store_id'       => (int) $r['store_id'],
            'customer_name'  => trim($r['customer_firstname'] . ' ' . $r['customer_lastname']),
            'customer_email' => $r['customer_email'],
            'guest'          => (bool) $r['customer_is_guest'],
            'total'          => $this->_money($r['grand_total']) . ' ' . $r['order_currency_code'],
            'base_total'     => $this->_money($r['base_grand_total']),
            'items_qty'      => (float) $r['total_qty_ordered'],
            'shipping'       => $r['shipping_description'],
        ], $rows);

        return ['rows' => count($orders), 'data' => [
            'period'        => $hasPeriod ? $range : 'any date',
            'matched'       => (int) $totals['orders'],
            'matched_total' => $this->_money($totals['revenue']) . ' ' . Mage::app()->getBaseCurrencyCode() . ' (base currency, every status in the match)',
            'listed'        => count($orders),
            'orders'        => $orders,
        ]];
    }

    private function _findOrder(array $args): array
    {
        $db = $this->_read();
        $select = $db->select()->from($this->_table('sales/order'));

        if (!empty($args['order_id'])) {
            $select->where('entity_id = ?', (int) $args['order_id']);
        } elseif (!empty($args['increment_id'])) {
            $select->where('increment_id = ?', trim((string) $args['increment_id'], " #\t"));
        } else {
            Mage::throwException('Give an increment_id or an order_id.');
        }

        $row = $db->fetchRow($select);

        if (!$row) {
            Mage::throwException('No order with that number.');
        }

        return $row;
    }

    private function _details(array $args): array
    {
        $db = $this->_read();
        $o  = $this->_findOrder($args);
        $id = (int) $o['entity_id'];

        $items = $db->fetchAll($db->select()
            ->from($this->_table('sales/order_item'), ['item_id', 'parent_item_id', 'sku', 'name', 'product_type', 'qty_ordered', 'qty_invoiced', 'qty_shipped', 'qty_refunded', 'qty_canceled', 'qty_backordered', 'price', 'row_total', 'discount_amount'])
            ->where('order_id = ?', $id));

        $addresses = $db->fetchAll($db->select()
            ->from($this->_table('sales/order_address'), ['address_type', 'firstname', 'lastname', 'company', 'street', 'city', 'region', 'postcode', 'country_id', 'telephone'])
            ->where('parent_id = ?', $id));

        // The method code and its title only: never the payment's other columns
        $method = (string) $db->fetchOne($db->select()->from($this->_table('sales/order_payment'), ['method'])->where('parent_id = ?', $id));
        $title  = $method !== '' ? (string) Mage::getStoreConfig('payment/' . $method . '/title', (int) $o['store_id']) : '';

        $history = $db->fetchAll($db->select()
            ->from($this->_table('sales/order_status_history'), ['created_at', 'status', 'comment', 'is_customer_notified', 'is_visible_on_front', 'entity_name'])
            ->where('parent_id = ?', $id)->order('created_at ASC')->order('entity_id ASC'));

        $shipments = $db->fetchAll($db->select()
            ->from($this->_table('sales/shipment'), ['entity_id', 'increment_id', 'created_at', 'total_qty'])
            ->where('order_id = ?', $id));

        $tracks = $shipments ? $db->fetchAll($db->select()
            ->from($this->_table('sales/shipment_track'), ['parent_id', 'carrier_code', 'title', 'track_number', 'created_at'])
            ->where('parent_id IN (?)', array_column($shipments, 'entity_id'))) : [];

        $invoices = $db->fetchAll($db->select()
            ->from($this->_table('sales/invoice'), ['increment_id', 'created_at', 'state', 'grand_total'])
            ->where('order_id = ?', $id));

        $memos = $db->fetchAll($db->select()
            ->from($this->_table('sales/creditmemo'), ['increment_id', 'created_at', 'state', 'grand_total', 'adjustment'])
            ->where('order_id = ?', $id));

        $invoiceStates = [1 => 'open (not yet paid)', 2 => 'paid', 3 => 'canceled'];
        $memoStates    = [1 => 'open', 2 => 'refunded', 3 => 'canceled'];

        $data = [
            'increment_id'  => $o['increment_id'],
            'order_id'      => $id,
            'admin_url'     => $this->_adminUrl('adminhtml/sales_order/view', ['order_id' => $id]),
            'placed'        => self::local($o['created_at']),
            'last_updated'  => self::local($o['updated_at']),
            'status'        => $o['status'],
            'state'         => $o['state'],
            'store_id'      => (int) $o['store_id'],
            'customer'      => [
                'full_name'   => trim($o['customer_firstname'] . ' ' . $o['customer_lastname']),
                'email'       => $o['customer_email'],
                'customer_id' => $o['customer_id'] ? (int) $o['customer_id'] : null,
                'guest'       => (bool) $o['customer_is_guest'],
                'group_id'    => (int) $o['customer_group_id'],
            ],
            'customer_note' => $o['customer_note'] ?? null,
            'addresses'     => $addresses,
            'items'         => array_map(fn ($i) => [
                'sku'          => $i['sku'],
                'name'         => $i['name'],
                'type'         => $i['product_type'],
                'child_of'     => $i['parent_item_id'] ? (int) $i['parent_item_id'] : null,
                'item_id'      => (int) $i['item_id'],
                'ordered'      => (float) $i['qty_ordered'],
                'invoiced'     => (float) $i['qty_invoiced'],
                'shipped'      => (float) $i['qty_shipped'],
                'refunded'     => (float) $i['qty_refunded'],
                'canceled'     => (float) $i['qty_canceled'],
                'backordered'  => (float) $i['qty_backordered'],
                'price'        => $this->_money($i['price']),
                'row_total'    => $this->_money($i['row_total']),
                'discount'     => $this->_money($i['discount_amount']),
            ], $items),
            'totals'        => [
                'currency'      => $o['order_currency_code'],
                'subtotal'      => $this->_money($o['subtotal']),
                'shipping'      => $this->_money($o['shipping_amount']),
                'discount'      => $this->_money($o['discount_amount']),
                'tax'           => $this->_money($o['tax_amount']),
                'grand_total'   => $this->_money($o['grand_total']),
                'paid'          => $this->_money($o['total_paid']),
                'invoiced'      => $this->_money($o['total_invoiced']),
                'refunded'      => $this->_money($o['total_refunded']),
                'canceled'      => $this->_money($o['total_canceled']),
                'coupon_code'   => $o['coupon_code'],
            ],
            'payment_method'  => $title !== '' ? $title . ' (' . $method . ')' : $method,
            'shipping_method' => $o['shipping_description'],
            'status_history'  => array_map(fn ($h) => [
                'at'               => self::local($h['created_at']),
                'status'           => $h['status'],
                'comment'          => $h['comment'],
                'customer_notified'=> $h['is_customer_notified'] === null ? 'n/a' : (bool) $h['is_customer_notified'],
                'about'            => $h['entity_name'],
            ], $history),
            'shipments' => array_map(fn ($s) => [
                'increment_id' => $s['increment_id'],
                'created'      => self::local($s['created_at']),
                'qty'          => (float) $s['total_qty'],
                'tracking'     => array_values(array_map(fn ($t) => [
                    'carrier' => $t['title'] ?: $t['carrier_code'],
                    'number'  => $t['track_number'],
                ], array_filter($tracks, fn ($t) => (int) $t['parent_id'] === (int) $s['entity_id']))),
            ], $shipments),
            'invoices' => array_map(fn ($i) => [
                'increment_id' => $i['increment_id'],
                'created'      => self::local($i['created_at']),
                'state'        => $invoiceStates[(int) $i['state']] ?? $i['state'],
                'total'        => $this->_money($i['grand_total']),
            ], $invoices),
            'credit_memos' => array_map(fn ($m) => [
                'increment_id' => $m['increment_id'],
                'created'      => self::local($m['created_at']),
                'state'        => $memoStates[(int) $m['state']] ?? $m['state'],
                'total'        => $this->_money($m['grand_total']),
            ], $memos),
        ];

        $data['observations'] = $this->observations($o, $items, $shipments, $invoices, $memos, $tracks);

        return ['rows' => 1, 'data' => $data];
    }

    /**
     * Plain checks for what usually goes wrong with an order, so "what's
     * wrong with this order" starts from facts rather than a guess.
     *
     * @return string[]
     */
    public function observations(array $o, array $items, array $shipments, array $invoices, array $memos, array $tracks, ?int $now = null): array
    {
        $now   = $now ?? time();
        $age   = ($now - strtotime($o['created_at'] . ' UTC')) / 86400;
        $notes = [];
        $state = (string) $o['state'];

        if ($state === 'pending_payment' && $age > 1) {
            $notes[] = sprintf('Waiting for payment for %.0f days: the payment was probably never completed at the gateway.', $age);
        }

        if ($state === 'new' && $age > 2) {
            $notes[] = sprintf('Still "new" (not invoiced) after %.0f days.', $age);
        }

        if ($state === 'holded') {
            $notes[] = 'The order is on hold; nothing more can happen to it until someone releases the hold.';
        }

        if ($state === 'payment_review') {
            $notes[] = 'The payment is under review (possible fraud check); it cannot be shipped until that is resolved.';
        }

        if (in_array($state, ['processing', 'new'], true) && !$invoices && (float) $o['total_paid'] <= 0) {
            $notes[] = 'No invoice and nothing recorded as paid.';
        }

        if ($invoices && !$shipments && !in_array($state, ['complete', 'closed', 'canceled'], true) && !(bool) $o['is_virtual']) {
            $last = max(array_map(fn ($i) => strtotime($i['created_at'] . ' UTC'), $invoices));
            $days = ($now - $last) / 86400;
            if ($days > 2) {
                $notes[] = sprintf('Invoiced %.0f days ago and not shipped yet.', $days);
            }
        }

        if ($shipments && !$tracks) {
            $notes[] = 'Shipped without a tracking number.';
        }

        foreach ($items as $i) {
            if ($i['parent_item_id']) {
                continue;
            }
            if ((float) $i['qty_backordered'] > 0) {
                $notes[] = sprintf('%s was backordered (%s units).', $i['sku'], (float) $i['qty_backordered']);
            }
            $open = (float) $i['qty_ordered'] - (float) $i['qty_shipped'] - (float) $i['qty_refunded'] - (float) $i['qty_canceled'];
            if ($shipments && $open > 0 && !in_array($state, ['canceled', 'closed'], true)) {
                $notes[] = sprintf('%s: %s units still to ship.', $i['sku'], $open);
            }
        }

        if ((float) $o['total_paid'] > 0 && abs((float) $o['total_paid'] - (float) $o['grand_total']) > 0.009 && !$memos) {
            $notes[] = sprintf('Paid %.2f against a total of %.2f.', $o['total_paid'], $o['grand_total']);
        }

        if ($state === 'complete' && (float) $o['total_refunded'] > 0) {
            $notes[] = 'Partly refunded.';
        }

        if ($state === 'canceled' && (float) $o['total_paid'] > 0 && (float) $o['total_refunded'] < (float) $o['total_paid']) {
            $notes[] = 'Canceled although money was taken and not fully refunded.';
        }

        return $notes ?: ['Nothing unusual found in the order record.'];
    }

    private function _shipments(array $args): array
    {
        $db     = $this->_read();
        $range  = self::range($args, 'yesterday');
        $select = $db->select()
            ->from(['s' => $this->_table('sales/shipment')], [])
            ->join(['o' => $this->_table('sales/order')], 'o.entity_id = s.order_id', []);
        $this->_applyRange($select, 's.created_at', $range);

        if (!empty($args['carrier'])) {
            $select->where('EXISTS (SELECT 1 FROM ' . $this->_table('sales/shipment_track') . ' t WHERE t.parent_id = s.entity_id AND (t.carrier_code LIKE ? OR t.title LIKE ?))', $this->_like((string) $args['carrier']));
        }

        $counts = $db->fetchRow((clone $select)->columns([
            'shipments' => new Zend_Db_Expr('COUNT(*)'),
            'orders'    => new Zend_Db_Expr('COUNT(DISTINCT s.order_id)'),
            'units'     => new Zend_Db_Expr('COALESCE(SUM(s.total_qty), 0)'),
        ]));

        $rows = $db->fetchAll($select->columns([
            's.entity_id', 'shipment' => 's.increment_id', 's.created_at', 'order' => 'o.increment_id', 's.total_qty',
            'tracking' => new Zend_Db_Expr('(SELECT GROUP_CONCAT(CONCAT(COALESCE(t.title, t.carrier_code), \' \', t.track_number) SEPARATOR \', \') FROM ' . $this->_table('sales/shipment_track') . ' t WHERE t.parent_id = s.entity_id)'),
        ])->order('s.created_at DESC')->limit($this->_limit($args)));

        return ['rows' => count($rows), 'data' => [
            'period'          => $range,
            'shipments'       => (int) $counts['shipments'],
            'orders_shipped'  => (int) $counts['orders'],
            'units'           => (float) $counts['units'],
            'list'            => array_map(fn ($r) => [
                'shipment' => $r['shipment'],
                'order'    => $r['order'],
                'created'  => self::local($r['created_at']),
                'qty'      => (float) $r['total_qty'],
                'tracking' => $r['tracking'],
            ], $rows),
        ]];
    }

    private function _documents(array $args): array
    {
        $db    = $this->_read();
        $memo  = ($args['type'] ?? '') === 'creditmemo';
        $range = self::range($args, 'last_30_days');
        $select = $db->select()
            ->from(['d' => $this->_table($memo ? 'sales/creditmemo' : 'sales/invoice')], [])
            ->join(['o' => $this->_table('sales/order')], 'o.entity_id = d.order_id', []);
        $this->_applyRange($select, 'd.created_at', $range);

        $totals = $db->fetchRow((clone $select)->columns([
            'count' => new Zend_Db_Expr('COUNT(*)'),
            'total' => new Zend_Db_Expr('COALESCE(SUM(d.base_grand_total), 0)'),
        ]));

        $rows = $db->fetchAll($select->columns([
            'number' => 'd.increment_id', 'd.created_at', 'order' => 'o.increment_id', 'd.state', 'd.base_grand_total',
        ])->order('d.created_at DESC')->limit($this->_limit($args)));

        return ['rows' => count($rows), 'data' => [
            'type'   => $memo ? 'credit memos (refunds)' : 'invoices',
            'period' => $range,
            'count'  => (int) $totals['count'],
            'total'  => $this->_money($totals['total']) . ' ' . Mage::app()->getBaseCurrencyCode(),
            'list'   => array_map(fn ($r) => [
                'number'  => $r['number'],
                'order'   => $r['order'],
                'created' => self::local($r['created_at']),
                'total'   => $this->_money($r['base_grand_total']),
            ], $rows),
        ]];
    }
}

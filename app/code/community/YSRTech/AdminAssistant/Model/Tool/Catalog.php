<?php
/**
 * The catalogue: products, stock, prices, categories, best sellers.
 */
class YSRTech_AdminAssistant_Model_Tool_Catalog extends YSRTech_AdminAssistant_Model_Tool_Abstract
{
    protected string $_acl = 'catalog';

    public function definitions(): array
    {
        return [
            'product_search' => [
                'description' => 'Find products by part of the name or SKU, by category, status or stock. Returns SKU, name, type, status, price, special price, quantity in stock and stock status, and the total matched.',
                'parameters'  => ['properties' => [
                    'query'       => ['type' => 'string', 'description' => 'Part of the name or SKU'],
                    'category_id' => ['type' => 'integer'],
                    'enabled'     => ['type' => 'boolean'],
                    'in_stock'    => ['type' => 'boolean'],
                    'type'        => ['type' => 'string', 'description' => 'simple, configurable, bundle, grouped, virtual, downloadable'],
                    'limit'       => ['type' => 'integer'],
                ]],
            ],
            'product_details' => [
                'description' => 'One product: prices (including special price dates and tier prices), stock settings, categories, visibility, and units sold and revenue over the last 30 and 365 days.',
                'parameters'  => ['properties' => [
                    'sku'        => ['type' => 'string'],
                    'product_id' => ['type' => 'integer'],
                ]],
            ],
            'low_stock' => [
                'description' => 'Enabled products at or below a stock quantity (by default each product\'s own "notify for quantity below" setting), or out of stock.',
                'parameters'  => ['properties' => [
                    'threshold'        => ['type' => 'number', 'description' => 'Quantity at or below which a product counts as low. Omit to use each product\'s own notify level.'],
                    'out_of_stock_only'=> ['type' => 'boolean'],
                    'limit'            => ['type' => 'integer'],
                ]],
            ],
            'best_sellers' => [
                'description' => 'Products ranked by units sold or by revenue in a period, from order lines (canceled orders left out).',
                'parameters'  => ['properties' => self::periodSchema() + [
                    'by'          => ['type' => 'string', 'enum' => ['units', 'revenue']],
                    'category_id' => ['type' => 'integer'],
                    'limit'       => ['type' => 'integer'],
                ]],
            ],
            'category_list' => [
                'description' => 'Categories with their ids, paths and number of products. Search by part of the name, or list the children of one category.',
                'parameters'  => ['properties' => [
                    'query'     => ['type' => 'string'],
                    'parent_id' => ['type' => 'integer'],
                    'limit'     => ['type' => 'integer'],
                ]],
            ],
        ];
    }

    public function run(string $name, array $args): array
    {
        switch ($name) {
            case 'product_search':  return $this->_search($args);
            case 'product_details': return $this->_details($args);
            case 'low_stock':       return $this->_lowStock($args);
            case 'best_sellers':    return $this->_bestSellers($args);
            case 'category_list':   return $this->_categories($args);
        }

        Mage::throwException('Unknown tool');
    }

    private function _collection(): Mage_Catalog_Model_Resource_Product_Collection
    {
        /** @var Mage_Catalog_Model_Resource_Product_Collection $c */
        $c = Mage::getResourceModel('catalog/product_collection');
        $c->setStoreId(Mage_Core_Model_App::ADMIN_STORE_ID)
            ->addAttributeToSelect(['name', 'price', 'special_price', 'special_from_date', 'special_to_date', 'status', 'visibility'])
            ->joinTable(['si' => 'cataloginventory/stock_item'], 'product_id=entity_id', ['qty', 'is_in_stock', 'notify_stock_qty', 'use_config_notify_stock_qty', 'manage_stock', 'use_config_manage_stock', 'backorders'], '{{table}}.stock_id=1', 'left');

        return $c;
    }

    private function _row(Mage_Catalog_Model_Product $p): array
    {
        return [
            'product_id'    => (int) $p->getId(),
            'sku'           => $p->getSku(),
            'name'          => $p->getName(),
            'type'          => $p->getTypeId(),
            'enabled'       => (int) $p->getStatus() === Mage_Catalog_Model_Product_Status::STATUS_ENABLED,
            'price'         => $p->getPrice() !== null ? $this->_money($p->getPrice()) : null,
            'special_price' => $p->getSpecialPrice() !== null ? $this->_money($p->getSpecialPrice()) : null,
            'qty'           => $p->getQty() !== null ? (float) $p->getQty() : null,
            'in_stock'      => (bool) $p->getIsInStock(),
        ];
    }

    private function _search(array $args): array
    {
        $c = $this->_collection();

        if (!empty($args['query'])) {
            $like = $this->_like((string) $args['query']);
            $c->addAttributeToFilter([['attribute' => 'name', 'like' => $like], ['attribute' => 'sku', 'like' => $like]]);
        }

        if (isset($args['enabled'])) {
            $c->addAttributeToFilter('status', $args['enabled'] ? Mage_Catalog_Model_Product_Status::STATUS_ENABLED : Mage_Catalog_Model_Product_Status::STATUS_DISABLED);
        }

        if (isset($args['in_stock'])) {
            $c->getSelect()->where('si.is_in_stock = ?', $args['in_stock'] ? 1 : 0);
        }

        if (!empty($args['type'])) {
            $c->addAttributeToFilter('type_id', (string) $args['type']);
        }

        if (!empty($args['category_id'])) {
            $c->getSelect()->where('e.entity_id IN (SELECT product_id FROM ' . $this->_table('catalog/category_product') . ' WHERE category_id = ?)', (int) $args['category_id']);
        }

        $matched = (int) $c->getSize();
        $c->setOrder('name', 'asc')->setPageSize($this->_limit($args))->setCurPage(1);
        $list = [];

        foreach ($c as $p) {
            $list[] = $this->_row($p);
        }

        return ['rows' => count($list), 'data' => ['matched' => $matched, 'currency' => Mage::app()->getBaseCurrencyCode(), 'products' => $list]];
    }

    private function _details(array $args): array
    {
        $c = $this->_collection()->addAttributeToSelect(['url_key', 'weight', 'cost', 'news_from_date']);

        if (!empty($args['product_id'])) {
            $c->addAttributeToFilter('entity_id', (int) $args['product_id']);
        } elseif (!empty($args['sku'])) {
            $c->addAttributeToFilter('sku', trim((string) $args['sku']));
        } else {
            Mage::throwException('Give a sku or a product_id.');
        }

        $p = $c->getFirstItem();

        if (!$p->getId()) {
            Mage::throwException('No product with that SKU.');
        }

        $db  = $this->_read();
        $id  = (int) $p->getId();
        $row = $this->_row($p);

        $categories = $db->fetchAll($db->select()
            ->from(['cp' => $this->_table('catalog/category_product')], ['category_id'])
            ->where('cp.product_id = ?', $id));
        $names = $this->_categoryNames(array_column($categories, 'category_id'));

        $tiers = $db->fetchAll($db->select()
            ->from($this->_table('catalog/product_attribute_tier_price'), ['qty', 'value', 'customer_group_id', 'all_groups', 'website_id'])
            ->where('entity_id = ?', $id));

        $sold = [];
        foreach (['last_30_days', 'last_365_days'] as $period) {
            $range  = self::range(['period' => $period]);
            $select = $db->select()
                ->from(['i' => $this->_table('sales/order_item')], [
                    'units'   => new Zend_Db_Expr('COALESCE(SUM(i.qty_ordered - i.qty_canceled - i.qty_refunded), 0)'),
                    'revenue' => new Zend_Db_Expr('COALESCE(SUM(i.base_row_total - i.base_discount_amount), 0)'),
                    'orders'  => new Zend_Db_Expr('COUNT(DISTINCT i.order_id)'),
                ])
                ->join(['o' => $this->_table('sales/order')], 'o.entity_id = i.order_id', [])
                ->where('i.product_id = ?', $id)
                ->where('i.parent_item_id IS NULL')
                ->where('o.state != ?', 'canceled');
            $this->_applyRange($select, 'o.created_at', $range);
            $s = $db->fetchRow($select);
            $sold[$period] = ['units' => (float) $s['units'], 'orders' => (int) $s['orders'], 'revenue' => $this->_money($s['revenue'])];
        }

        $visibility = Mage_Catalog_Model_Product_Visibility::getOptionArray();

        return ['rows' => 1, 'data' => $row + [
            'admin_url'         => $this->_adminUrl('adminhtml/catalog_product/edit', ['id' => $id]),
            'visibility'        => $visibility[(int) $p->getVisibility()] ?? $p->getVisibility(),
            'special_from'      => $p->getSpecialFromDate(),
            'special_to'        => $p->getSpecialToDate(),
            'cost'              => $p->getCost() !== null ? $this->_money($p->getCost()) : null,
            'weight'            => $p->getWeight() !== null ? (float) $p->getWeight() : null,
            'tier_prices'       => $tiers,
            'manage_stock'      => $p->getUseConfigManageStock() ? 'store default (' . (Mage::getStoreConfigFlag('cataloginventory/item_options/manage_stock') ? 'yes' : 'no') . ')' : (bool) $p->getManageStock(),
            'notify_below'      => $p->getUseConfigNotifyStockQty() ? (float) Mage::getStoreConfig('cataloginventory/item_options/notify_stock_qty') : (float) $p->getNotifyStockQty(),
            'backorders'        => ['0' => 'no', '1' => 'allowed', '2' => 'allowed and customer notified'][(string) (int) $p->getBackorders()] ?? $p->getBackorders(),
            'categories'        => array_values($names),
            'sold'              => $sold,
            'currency'          => Mage::app()->getBaseCurrencyCode(),
        ]];
    }

    private function _categoryNames(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if (!$ids) {
            return [];
        }

        $out = [];
        /** @var Mage_Catalog_Model_Resource_Category_Collection $c */
        $c = Mage::getResourceModel('catalog/category_collection');
        $c->setStoreId(0)->addAttributeToSelect('name')->addAttributeToFilter('entity_id', ['in' => $ids]);

        foreach ($c as $cat) {
            $out[(int) $cat->getId()] = ['category_id' => (int) $cat->getId(), 'name' => $cat->getName(), 'path' => $cat->getPath()];
        }

        return $out;
    }

    private function _lowStock(array $args): array
    {
        $c = $this->_collection()->addAttributeToFilter('status', Mage_Catalog_Model_Product_Status::STATUS_ENABLED);
        $c->addAttributeToFilter('type_id', ['in' => ['simple', 'virtual', 'downloadable']]);
        $select = $c->getSelect();

        // Products that do not manage stock are never low
        $manageDefault = Mage::getStoreConfigFlag('cataloginventory/item_options/manage_stock') ? 1 : 0;
        $select->where("IF(si.use_config_manage_stock = 1, {$manageDefault}, si.manage_stock) = 1");

        if (!empty($args['out_of_stock_only'])) {
            $select->where('si.is_in_stock = 0 OR si.qty <= 0');
            $basis = 'out of stock';
        } elseif (isset($args['threshold']) && is_numeric($args['threshold'])) {
            $select->where('si.qty <= ?', (float) $args['threshold']);
            $basis = 'quantity at or below ' . (float) $args['threshold'];
        } else {
            $notify = (float) Mage::getStoreConfig('cataloginventory/item_options/notify_stock_qty');
            $select->where("si.qty <= IF(si.use_config_notify_stock_qty = 1, {$notify}, si.notify_stock_qty) OR si.is_in_stock = 0");
            $basis = 'each product\'s "notify for quantity below" level (store default ' . $notify . ')';
        }

        $matched = (int) $c->getSize();
        $c->getSelect()->order('si.qty ASC');
        $c->setPageSize($this->_limit($args, 50))->setCurPage(1);
        $list = [];

        foreach ($c as $p) {
            $list[] = $this->_row($p);
        }

        return ['rows' => count($list), 'data' => ['basis' => $basis, 'matched' => $matched, 'products' => $list]];
    }

    private function _bestSellers(array $args): array
    {
        $db    = $this->_read();
        $range = self::range($args, 'last_30_days');
        $by    = ($args['by'] ?? 'units') === 'revenue' ? 'revenue' : 'units';

        $select = $db->select()
            ->from(['i' => $this->_table('sales/order_item')], [
                'sku'     => new Zend_Db_Expr('MAX(i.sku)'),
                'name'    => new Zend_Db_Expr('MAX(i.name)'),
                'product_id' => 'i.product_id',
                'units'   => new Zend_Db_Expr('SUM(i.qty_ordered - i.qty_canceled - i.qty_refunded)'),
                'revenue' => new Zend_Db_Expr('SUM(i.base_row_total - i.base_discount_amount)'),
                'orders'  => new Zend_Db_Expr('COUNT(DISTINCT i.order_id)'),
            ])
            ->join(['o' => $this->_table('sales/order')], 'o.entity_id = i.order_id', [])
            ->where('i.parent_item_id IS NULL')
            ->where('o.state != ?', 'canceled')
            ->group('i.product_id')
            ->order($by === 'revenue' ? 'revenue DESC' : 'units DESC')
            ->limit($this->_limit($args));
        $this->_applyRange($select, 'o.created_at', $range);

        if (!empty($args['category_id'])) {
            $select->where('i.product_id IN (SELECT product_id FROM ' . $this->_table('catalog/category_product') . ' WHERE category_id = ?)', (int) $args['category_id']);
        }

        $rows = $db->fetchAll($select);

        return ['rows' => count($rows), 'data' => [
            'period'    => $range,
            'ranked_by' => $by,
            'currency'  => Mage::app()->getBaseCurrencyCode(),
            'products'  => array_map(fn ($r, $i) => [
                'rank'    => $i + 1,
                'sku'     => $r['sku'],
                'name'    => $r['name'],
                'units'   => (float) $r['units'],
                'orders'  => (int) $r['orders'],
                'revenue' => $this->_money($r['revenue']),
            ], $rows, array_keys($rows)),
        ]];
    }

    private function _categories(array $args): array
    {
        /** @var Mage_Catalog_Model_Resource_Category_Collection $c */
        $c = Mage::getResourceModel('catalog/category_collection');
        $c->setStoreId(0)->addAttributeToSelect(['name', 'is_active'])->addAttributeToFilter('level', ['gt' => 0]);

        if (!empty($args['query'])) {
            $c->addAttributeToFilter('name', ['like' => $this->_like((string) $args['query'])]);
        }

        if (!empty($args['parent_id'])) {
            $c->addAttributeToFilter('parent_id', (int) $args['parent_id']);
        }

        $c->setOrder('path', 'asc')->setPageSize($this->_limit($args, 50))->setCurPage(1);
        $c->getSelect()->columns(['product_count' => new Zend_Db_Expr('(SELECT COUNT(*) FROM ' . $this->_table('catalog/category_product') . ' cp WHERE cp.category_id = e.entity_id)')]);
        $list = [];

        foreach ($c as $cat) {
            $list[] = [
                'category_id' => (int) $cat->getId(),
                'name'        => $cat->getName(),
                'parent_id'   => (int) $cat->getParentId(),
                'level'       => (int) $cat->getLevel(),
                'active'      => (bool) $cat->getIsActive(),
                'products'    => (int) $cat->getProductCount(),
            ];
        }

        return ['rows' => count($list), 'data' => ['matched' => (int) $c->getSize(), 'categories' => $list]];
    }
}

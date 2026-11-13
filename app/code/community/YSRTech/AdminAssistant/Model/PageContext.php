<?php
/**
 * What the admin is looking at, so "this order" and "this customer" mean
 * something. Read from the page's route when the panel is drawn; on each
 * question only the type and id come back from the browser, and the
 * description is built again here from the database.
 */
class YSRTech_AdminAssistant_Model_PageContext
{
    /** controller => [type, request parameter] */
    public const ROUTES = [
        'sales_order'          => ['order', 'order_id'],
        'sales_order_edit'     => ['order', 'order_id'],
        'sales_invoice'        => ['invoice', 'invoice_id'],
        'sales_order_invoice'  => ['invoice', 'invoice_id'],
        'sales_shipment'       => ['shipment', 'shipment_id'],
        'sales_order_shipment' => ['shipment', 'shipment_id'],
        'sales_creditmemo'     => ['creditmemo', 'creditmemo_id'],
        'sales_order_creditmemo' => ['creditmemo', 'creditmemo_id'],
        'customer'             => ['customer', 'id'],
        'catalog_product'      => ['product', 'id'],
        'catalog_category'     => ['category', 'id'],
        'ysrtech_campaign'     => ['campaign', 'id'],
        'ysrtech_segment'      => ['segment', 'id'],
    ];

    /**
     * @return array{type:string, id:int, page:string}
     */
    public function fromRequest(Mage_Core_Controller_Request_Http $request): array
    {
        $controller = (string) $request->getControllerName();
        $action     = (string) $request->getActionName();
        $page       = $controller . '/' . $action;

        if (isset(self::ROUTES[$controller]) && in_array($action, ['view', 'edit', 'index'], true)) {
            [$type, $param] = self::ROUTES[$controller];
            $id = (int) $request->getParam($param);

            if ($id > 0) {
                return ['type' => $type, 'id' => $id, 'page' => $page];
            }
        }

        return ['type' => 'page', 'id' => 0, 'page' => $page];
    }

    /**
     * One line for the model, built from the database: never from text the
     * browser sent.
     */
    public function describe(array $context): string
    {
        $type = (string) ($context['type'] ?? '');
        $id   = (int) ($context['id'] ?? 0);
        $page = preg_replace('#[^a-z0-9_/]#i', '', (string) ($context['page'] ?? ''));
        $db   = Mage::getSingleton('core/resource')->getConnection('core_read');
        $t    = static fn ($e) => Mage::getSingleton('core/resource')->getTableName($e);

        try {
            switch ($type) {
                case 'order':
                    $inc = $db->fetchOne($db->select()->from($t('sales/order'), ['increment_id'])->where('entity_id = ?', $id));
                    return $inc ? "The admin is viewing order #{$inc} (order_id {$id}). \"This order\" means it." : '';

                case 'invoice':
                case 'shipment':
                case 'creditmemo':
                    $table = ['invoice' => 'sales/invoice', 'shipment' => 'sales/shipment', 'creditmemo' => 'sales/creditmemo'][$type];
                    $row   = $db->fetchRow($db->select()->from(['d' => $t($table)], ['increment_id'])
                        ->join(['o' => $t('sales/order')], 'o.entity_id = d.order_id', ['order' => 'increment_id', 'order_id' => 'entity_id'])
                        ->where('d.entity_id = ?', $id));
                    return $row ? "The admin is viewing {$type} #{$row['increment_id']} of order #{$row['order']} (order_id {$row['order_id']})." : '';

                case 'customer':
                    $email = $db->fetchOne($db->select()->from($t('customer/entity'), ['email'])->where('entity_id = ?', $id));
                    return $email ? "The admin is viewing customer_id {$id} ({$email}). \"This customer\" means them." : '';

                case 'product':
                    $sku = $db->fetchOne($db->select()->from($t('catalog/product'), ['sku'])->where('entity_id = ?', $id));
                    return $sku ? "The admin is viewing the product with SKU {$sku} (product_id {$id}). \"This product\" means it." : '';

                case 'category':
                    return "The admin is viewing category_id {$id}.";

                case 'campaign':
                    if (Mage::helper('core')->isModuleEnabled('YSRTech_EmailCampaigns')) {
                        $name = $db->fetchOne($db->select()->from($t('ysrtech_emailcampaigns/campaign'), ['name'])->where('campaign_id = ?', $id));
                        return $name !== false ? "The admin is viewing email campaign_id {$id}. \"This campaign\" means it." : '';
                    }
                    return '';

                case 'segment':
                    return "The admin is viewing Email Campaigns segment_id {$id}.";
            }
        } catch (Throwable $e) {
            Mage::logException($e);
        }

        return $page !== '' ? "The admin asked from the admin page {$page}." : '';
    }
}

<?php
/**
 * The assistant button and drawer, on every admin page for whoever may see
 * it (Helper::showPanel).
 */
class YSRTech_AdminAssistant_Block_Adminhtml_Panel extends Mage_Adminhtml_Block_Template
{
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('ysrtech_adminassistant/panel.phtml');
    }

    protected function _toHtml()
    {
        return Mage::helper('ysrtech_adminassistant')->showPanel() ? parent::_toHtml() : '';
    }

    public function config(): array
    {
        $h       = Mage::helper('ysrtech_adminassistant');
        $context = Mage::getSingleton('ysrtech_adminassistant/pageContext')->fromRequest($this->getRequest());
        $user    = $h->adminUser();

        return [
            'enabled'     => $h->isEnabled(),
            'off_message' => $h->__('The AI Assistant is switched off. Turning it on sends store data, including customer names, addresses and orders, to the AI provider, so read the privacy note in its settings first.'),
            'settings'    => $h->isAllowed(YSRTech_AdminAssistant_Helper_Data::ACL_CONFIG) ? $this->getUrl('adminhtml/system_config/edit', ['section' => 'ysrtech_adminassistant']) : null,
            'urls'        => [
                'chat'          => $this->getUrl('adminhtml/ysrtech_assistant/chat'),
                'conversations' => $this->getUrl('adminhtml/ysrtech_assistant/conversations'),
                'conversation'  => $this->getUrl('adminhtml/ysrtech_assistant/conversation'),
                'delete'        => $this->getUrl('adminhtml/ysrtech_assistant/delete'),
            ],
            'form_key'    => Mage::getSingleton('core/session')->getFormKey(),
            'page'        => $context,
            'user'        => $user ? (string) $user->getFirstname() : '',
            'suggestions' => $this->_suggestions($context['type']),
            'labels'      => [
                'order_search' => $h->__('Searching orders'), 'order_details' => $h->__('Reading the order'),
                'shipment_search' => $h->__('Looking at shipments'), 'invoice_creditmemo_search' => $h->__('Looking at invoices and refunds'),
                'customer_search' => $h->__('Searching customers'), 'customer_details' => $h->__('Reading the customer'),
                'top_customers' => $h->__('Ranking customers'), 'customer_groups' => $h->__('Reading customer groups'),
                'product_search' => $h->__('Searching products'), 'product_details' => $h->__('Reading the product'),
                'low_stock' => $h->__('Checking stock'), 'best_sellers' => $h->__('Ranking best sellers'), 'category_list' => $h->__('Reading categories'),
                'sales_summary' => $h->__('Adding up sales'), 'sales_compare' => $h->__('Comparing periods'),
                'campaign_list' => $h->__('Listing campaigns'), 'campaign_results' => $h->__('Reading campaign results'),
                'email_overview' => $h->__('Reading email performance'), 'segment_list' => $h->__('Listing segments'), 'list_growth' => $h->__('Measuring list growth'),
                'email_activity' => $h->__('Reading email tracking'), 'campaign_recipients' => $h->__('Listing campaign recipients'),
                'automation_results' => $h->__('Reading automation results'),
                'config_value' => $h->__('Reading settings'), 'cron_status' => $h->__('Checking cron'), 'recent_errors' => $h->__('Reading the logs'),
                'store_overview' => $h->__('Reading store setup'), 'report_schema' => $h->__('Reading the report tables'), 'report_query' => $h->__('Running a report query'),
            ],
        ];
    }

    private function _suggestions(string $type): array
    {
        $h = Mage::helper('ysrtech_adminassistant');

        switch ($type) {
            case 'order':
            case 'invoice':
            case 'shipment':
            case 'creditmemo':
                return [$h->__('What\'s wrong with this order?'), $h->__('Has this order shipped, and with what tracking?'), $h->__('What else has this customer ordered?')];
            case 'customer':
                return [$h->__('Summarise this customer'), $h->__('What is this customer\'s lifetime value?'), $h->__('Is this customer subscribed to the newsletter?')];
            case 'product':
                return [$h->__('How is this product selling?'), $h->__('How much of this product is in stock?')];
            case 'campaign':
                return [$h->__('How did this campaign do?'), $h->__('How much revenue did this campaign bring in?')];
        }

        return [
            $h->__('How many orders shipped yesterday?'),
            $h->__('Top 20 customers this year'),
            $h->__('Sales this week compared with last week'),
            $h->__('Which products are low on stock?'),
        ];
    }

    public function assetUrl(string $file): string
    {
        // The file ships in default/default; a store's own admin theme (Pardes
        // has one) falls back to it for the URL but not for this path
        $path = Mage::getBaseDir('skin') . DS . 'adminhtml' . DS . 'default' . DS . 'default' . DS . 'ysrtech' . DS . 'adminassistant' . DS . $file;

        return $this->getSkinUrl('ysrtech/adminassistant/' . $file) . (is_file($path) ? '?v=' . filemtime($path) : '');
    }
}

<?php
/**
 * Email Campaigns' results, when that module is installed: campaigns,
 * their opens, clicks and revenue, segments and list growth.
 *
 * Counted the way Email Campaigns' own dashboard counts: a real open is an
 * open beyond those a mail server's scanner made, and delivered means sent
 * and not bounced.
 */
class YSRTech_AdminAssistant_Model_Tool_Campaigns extends YSRTech_AdminAssistant_Model_Tool_Abstract
{
    protected string $_acl = 'campaigns';

    public function available(): bool
    {
        return Mage::helper('core')->isModuleEnabled('YSRTech_EmailCampaigns')
            && $this->_read()->isTableExists($this->_table('ysrtech_emailcampaigns/campaign'));
    }

    public function definitions(): array
    {
        return [
            'campaign_list' => [
                'description' => 'Email campaigns, newest first, each with status, when it was sent, recipients, delivered, real opens, clicks, bounces, complaints, and orders and revenue credited to it. Filter by name, status or the period it was sent in.',
                'parameters'  => ['properties' => self::periodSchema() + [
                    'query'  => ['type' => 'string', 'description' => 'Part of the campaign name or subject'],
                    'status' => ['type' => 'string', 'description' => 'draft, scheduled, sending, sent, paused, cancelled'],
                    'limit'  => ['type' => 'integer'],
                ]],
            ],
            'campaign_results' => [
                'description' => 'One campaign in full: subject, segments, every count and rate, credited orders and revenue, and its most-clicked links. Give the id, or part of the name (the latest match is used).',
                'parameters'  => ['properties' => [
                    'campaign_id' => ['type' => 'integer'],
                    'name'        => ['type' => 'string'],
                ]],
            ],
            'email_overview' => [
                'description' => 'All email sent (campaigns and automations) over the last 7, 14 or 30 days: totals against the period before, rates, the verdict Email Campaigns gives on sender reputation, and the split by mailbox provider. Also revenue credited to email.',
                'parameters'  => ['properties' => [
                    'days' => ['type' => 'integer', 'enum' => [7, 14, 30]],
                    'type' => ['type' => 'string', 'enum' => ['all', 'campaign', 'automation']],
                ]],
            ],
            'segment_list' => [
                'description' => 'Email Campaigns segments with how many subscribers each holds and when it was last counted.',
                'parameters'  => ['properties' => ['query' => ['type' => 'string'], 'limit' => ['type' => 'integer']]],
            ],
            'list_growth' => [
                'description' => 'Newsletter subscribers: how many are subscribed now, and new subscriptions and unsubscribes per day, week or month in a period.',
                'parameters'  => ['properties' => self::periodSchema() + [
                    'group_by' => ['type' => 'string', 'enum' => ['day', 'week', 'month']],
                ]],
            ],
        ];
    }

    public function run(string $name, array $args): array
    {
        switch ($name) {
            case 'campaign_list':    return $this->_list($args);
            case 'campaign_results': return $this->_results($args);
            case 'email_overview':   return $this->_overview($args);
            case 'segment_list':     return $this->_segments($args);
            case 'list_growth':      return $this->_growth($args);
        }

        Mage::throwException('Unknown tool');
    }

    /**
     * @param  int[] $ids
     * @return array<int, array>
     */
    public function stats(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $db   = $this->_read();
        $rows = $db->fetchAll($db->select()
            ->from(['q' => $this->_table('ysrtech_emailcampaigns/queue')], [
                'campaign_id',
                'sent'        => new Zend_Db_Expr("SUM(q.status = 'sent')"),
                'failed'      => new Zend_Db_Expr("SUM(q.status = 'failed')"),
                'waiting'     => new Zend_Db_Expr("SUM(q.status IN ('pending', 'processing'))"),
                'delivered'   => new Zend_Db_Expr("SUM(q.status = 'sent' AND q.bounced_at IS NULL)"),
                'opened'      => new Zend_Db_Expr("SUM(q.status = 'sent' AND q.bounced_at IS NULL AND q.open_count > q.machine_open_count)"),
                'clicked'     => new Zend_Db_Expr("SUM(q.status = 'sent' AND q.click_count > 0)"),
                'bounced'     => new Zend_Db_Expr("SUM(q.bounced_at IS NOT NULL)"),
                'complained'  => new Zend_Db_Expr("SUM(q.complained_at IS NOT NULL)"),
            ])
            ->where('q.campaign_id IN (?)', $ids)
            ->group('q.campaign_id'));

        $money = [];
        try {
            $money = Mage::getSingleton('ysrtech_emailcampaigns/conversions')->grouped('campaign_id', $ids);
        } catch (Throwable $e) {
            // Older Email Campaigns without conversions: no revenue figures
        }

        $out = [];

        foreach ($rows as $r) {
            $id       = (int) $r['campaign_id'];
            $sent     = (int) $r['sent'];
            $deliv    = (int) $r['delivered'];
            $rate     = static fn ($n, $d) => $d ? round($n / $d * 100, 1) . '%' : null;
            $out[$id] = [
                'sent'            => $sent,
                'failed'          => (int) $r['failed'],
                'still_queued'    => (int) $r['waiting'],
                'delivered'       => $deliv,
                'real_opens'      => (int) $r['opened'],
                'open_rate'       => $rate((int) $r['opened'], $deliv),
                'clicks'          => (int) $r['clicked'],
                'click_rate'      => $rate((int) $r['clicked'], $deliv),
                'bounces'         => (int) $r['bounced'],
                'bounce_rate'     => $rate((int) $r['bounced'], $sent),
                'complaints'      => (int) $r['complained'],
                'orders_credited' => $money[$id]['orders'] ?? 0,
                'revenue_credited'=> $money[$id]['revenue'] ?? 0,
            ];
        }

        return $out;
    }

    private function _list(array $args): array
    {
        $db     = $this->_read();
        $select = $db->select()->from($this->_table('ysrtech_emailcampaigns/campaign'), ['campaign_id', 'name', 'subject', 'status', 'scheduled_at', 'sent_at', 'created_at']);

        if (!empty($args['query'])) {
            $like = $this->_like((string) $args['query']);
            $select->where('name LIKE ? OR subject LIKE ?', $like);
        }

        if (!empty($args['status'])) {
            $select->where('status = ?', (string) $args['status']);
        }

        if (isset($args['period']) || isset($args['from']) || isset($args['to'])) {
            $this->_applyRange($select, 'sent_at', self::range($args));
        }

        $rows  = $db->fetchAll($select->order(new Zend_Db_Expr('COALESCE(sent_at, scheduled_at, created_at) DESC'))->limit($this->_limit($args)));
        $stats = $this->stats(array_map('intval', array_column($rows, 'campaign_id')));

        $list = array_map(fn ($r) => [
            'campaign_id' => (int) $r['campaign_id'],
            'name'        => $r['name'],
            'subject'     => $r['subject'],
            'status'      => $r['status'],
            'scheduled'   => self::local($r['scheduled_at']),
            'sent'        => self::local($r['sent_at']),
        ] + ($stats[(int) $r['campaign_id']] ?? []), $rows);

        return ['rows' => count($list), 'data' => ['currency' => Mage::app()->getBaseCurrencyCode(), 'campaigns' => $list]];
    }

    private function _results(array $args): array
    {
        $db     = $this->_read();
        $select = $db->select()->from($this->_table('ysrtech_emailcampaigns/campaign'), ['campaign_id', 'name', 'subject', 'preheader', 'status', 'scheduled_at', 'sent_at', 'created_at', 'send_to_all']);

        if (!empty($args['campaign_id'])) {
            $select->where('campaign_id = ?', (int) $args['campaign_id']);
        } elseif (!empty($args['name'])) {
            $select->where('name LIKE ? OR subject LIKE ?', $this->_like((string) $args['name']))->order(new Zend_Db_Expr('COALESCE(sent_at, created_at) DESC'));
        } else {
            $select->where('sent_at IS NOT NULL')->order('sent_at DESC'); // the latest sent
        }

        $c = $db->fetchRow($select->limit(1));

        if (!$c) {
            Mage::throwException('No campaign found.');
        }

        $id = (int) $c['campaign_id'];

        $segments = $db->fetchAll($db->select()
            ->from(['cs' => $this->_table('ysrtech_emailcampaigns/campaign_segment')], ['excluded' => 'cs.is_excluded'])
            ->join(['s' => $this->_table('ysrtech_emailcampaigns/segment')], 's.segment_id = cs.segment_id', ['name'])
            ->where('cs.campaign_id = ?', $id));

        $links = [];
        try {
            $links = Mage::getSingleton('ysrtech_emailcampaigns/conversions')->linksForCampaign($id, 10);
        } catch (Throwable $e) {
            // Not in older versions
        }

        return ['rows' => 1, 'data' => [
            'campaign_id' => $id,
            'admin_url'   => $this->_adminUrl('adminhtml/ysrtech_campaign/edit', ['id' => $id]),
            'name'        => $c['name'],
            'subject'     => $c['subject'],
            'preheader'   => $c['preheader'],
            'status'      => $c['status'],
            'scheduled'   => self::local($c['scheduled_at']),
            'sent'        => self::local($c['sent_at']),
            'audience'    => $c['send_to_all'] ? 'all subscribers' : $segments,
            'results'     => $this->stats([$id])[$id] ?? 'nothing sent yet',
            'currency'    => Mage::app()->getBaseCurrencyCode(),
            'top_links'   => $links,
        ]];
    }

    private function _overview(array $args): array
    {
        $days  = in_array((int) ($args['days'] ?? 14), [7, 14, 30], true) ? (int) ($args['days'] ?? 14) : 14;
        $type  = ['campaign' => 'campaign', 'automation' => 'automation'][$args['type'] ?? ''] ?? '';
        $stats = Mage::getModel('ysrtech_emailcampaigns/dashboard_stats')->compute($days, $type);

        $from  = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $money = null;
        try {
            $money = Mage::getSingleton('ysrtech_emailcampaigns/conversions')->since($from, null, $type ?: null);
        } catch (Throwable $e) {
            // Older versions
        }

        unset($stats['series']); // one row a day is more than an answer needs

        return ['rows' => 1, 'data' => $stats + [
            'note'             => 'opened = real opens (scanner opens are counted separately as "machine"); delivered = sent and not bounced; previous = the same number of days before',
            'revenue_credited' => $money,
        ]];
    }

    private function _segments(array $args): array
    {
        $db     = $this->_read();
        $select = $db->select()->from($this->_table('ysrtech_emailcampaigns/segment'), ['segment_id', 'name', 'is_active', 'customer_count', 'last_reindexed_at']);

        if (!empty($args['query'])) {
            $select->where('name LIKE ?', $this->_like((string) $args['query']));
        }

        $rows = $db->fetchAll($select->order('name ASC')->limit($this->_limit($args, 50)));

        return ['rows' => count($rows), 'data' => ['segments' => array_map(fn ($r) => [
            'segment_id'   => (int) $r['segment_id'],
            'name'         => $r['name'],
            'active'       => (bool) $r['is_active'],
            'subscribers'  => (int) $r['customer_count'],
            'last_counted' => self::local($r['last_reindexed_at']),
        ], $rows)]];
    }

    private function _growth(array $args): array
    {
        $db    = $this->_read();
        $table = $this->_table('newsletter/subscriber');
        $range = self::range($args, 'last_90_days');
        $group = $args['group_by'] ?? 'week';
        $fmt   = ['day' => '%Y-%m-%d', 'week' => '%x-W%v', 'month' => '%Y-%m'][$group] ?? '%x-W%v';
        $key   = $this->_localDayExpr('change_status_at', $fmt);

        $now = $db->fetchPairs($db->select()->from($table, ['subscriber_status', new Zend_Db_Expr('COUNT(*)')])->group('subscriber_status'));
        $names = [1 => 'subscribed', 2 => 'not_active', 3 => 'unsubscribed', 4 => 'unconfirmed'];

        $select = $db->select()->from($table, [
            'period'       => $key,
            'subscribed'   => new Zend_Db_Expr('SUM(subscriber_status = 1)'),
            'unsubscribed' => new Zend_Db_Expr('SUM(subscriber_status = 3)'),
        ])->group($key)->order('period ASC');
        $this->_applyRange($select, 'change_status_at', $range);
        $rows = $db->fetchAll($select);

        $counts = [];
        foreach ($now as $status => $n) {
            $counts[$names[(int) $status] ?? (string) $status] = (int) $n;
        }

        return ['rows' => count($rows), 'data' => [
            'period'   => $range,
            'now'      => $counts,
            'note'     => 'Counted by the date of each address\'s latest change: someone who subscribed and later unsubscribed counts once, as an unsubscribe on the later date.',
            'by_' . $group => $rows,
        ]];
    }
}

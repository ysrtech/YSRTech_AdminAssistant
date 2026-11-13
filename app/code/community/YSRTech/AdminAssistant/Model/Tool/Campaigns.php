<?php
/**
 * Email Campaigns' results, when that module is installed: campaigns,
 * their opens, clicks and revenue, segments and list growth, and the
 * tracking behind them - each address's messages, who opened or clicked a
 * campaign, automations, and why an address is not mailed.
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
            'email_activity' => [
                'description' => 'One person\'s email tracking: every campaign and automation email sent to an address (newest first) with whether it was delivered, opened (real opens, not mail-scanner opens), clicked, bounced or complained, the links they clicked, plus their newsletter status and whether the address is suppressed and why. Give the email, or a customer_id.',
                'parameters'  => ['properties' => [
                    'email'       => ['type' => 'string'],
                    'customer_id' => ['type' => 'integer'],
                    'limit'       => ['type' => 'integer', 'description' => 'Messages to list, default 20'],
                ]],
            ],
            'campaign_recipients' => [
                'description' => 'Who received one campaign, filtered by what they did: opened, clicked, did not open, bounced, complained, or failed to send. Returns the count and the addresses with times. Give the campaign id or part of its name (the latest match), or neither for the latest sent campaign.',
                'parameters'  => ['properties' => [
                    'campaign_id' => ['type' => 'integer'],
                    'name'        => ['type' => 'string'],
                    'filter'      => ['type' => 'string', 'enum' => ['opened', 'clicked', 'not_opened', 'bounced', 'complained', 'failed', 'all']],
                    'limit'       => ['type' => 'integer'],
                ], 'required' => ['filter']],
            ],
            'automation_results' => [
                'description' => 'Email Campaigns automations (triggered emails: new order, order status, abandoned cart and so on): whether each is active, its trigger, and for a period how many it sent, delivered, opened, clicked, bounced, and the orders and revenue credited. Give automation_id for one automation broken down by step.',
                'parameters'  => ['properties' => self::periodSchema() + [
                    'automation_id' => ['type' => 'integer'],
                ]],
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
            case 'email_activity':      return $this->_activity($args);
            case 'campaign_recipients': return $this->_recipients($args);
            case 'automation_results':  return $this->_automations($args);
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
            'about'             => 'opened = real opens (scanner opens are counted separately as "machine"); delivered = sent and not bounced; previous = the same number of days before',
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
            'about'     => 'Counted by the date of each address\'s latest change: someone who subscribed and later unsubscribed counts once, as an unsubscribe on the later date.',
            'by_' . $group => $rows,
        ]];
    }

    /** What happened to one queued message, in words */
    private function _outcome(array $q): array
    {
        $realOpens = max(0, (int) $q['open_count'] - (int) $q['machine_open_count']);

        return [
            'status'     => $q['status'] === 'sent' ? ($q['bounced_at'] ? 'bounced' : 'sent') : $q['status'],
            'sent'       => self::local($q['sent_at']),
            'delivered'  => $q['status'] === 'sent' && !$q['bounced_at'] ? (self::local($q['delivered_at']) ?? 'yes') : 'no',
            'opened'     => $realOpens > 0 ? self::local($q['opened_at']) . ' (' . $realOpens . ' real open' . ($realOpens === 1 ? '' : 's') . ')' : ((int) $q['open_count'] > 0 ? 'only mail-scanner opens' : 'no'),
            'clicked'    => (int) $q['click_count'] > 0 ? self::local($q['clicked_at']) . ' (' . (int) $q['click_count'] . ' click' . ((int) $q['click_count'] === 1 ? '' : 's') . ')' : 'no',
            'bounced'    => self::local($q['bounced_at']),
            'complained' => self::local($q['complained_at']),
        ];
    }

    private const QUEUE_COLUMNS = ['queue_id', 'email', 'campaign_id', 'automation_id', 'step_order', 'status', 'sent_at', 'delivered_at',
        'opened_at', 'open_count', 'machine_open_count', 'clicked_at', 'click_count', 'bounced_at', 'complained_at', 'error_message'];

    private function _activity(array $args): array
    {
        $db    = $this->_read();
        $email = trim((string) ($args['email'] ?? ''));

        if ($email === '' && !empty($args['customer_id'])) {
            $email = (string) $db->fetchOne($db->select()->from($this->_table('customer/entity'), ['email'])->where('entity_id = ?', (int) $args['customer_id']));
        }

        if ($email === '') {
            Mage::throwException('Give an email address or a customer_id.');
        }

        $queue = $this->_table('ysrtech_emailcampaigns/queue');
        $base  = $db->select()->from(['q' => $queue], [])->where('q.email = ?', $email);

        $totals = $db->fetchRow((clone $base)->columns([
            'messages'   => new Zend_Db_Expr('COUNT(*)'),
            'sent'       => new Zend_Db_Expr("SUM(q.status = 'sent')"),
            'opened'     => new Zend_Db_Expr("SUM(q.status = 'sent' AND q.bounced_at IS NULL AND q.open_count > q.machine_open_count)"),
            'clicked'    => new Zend_Db_Expr('SUM(q.click_count > 0)'),
            'bounced'    => new Zend_Db_Expr('SUM(q.bounced_at IS NOT NULL)'),
            'complained' => new Zend_Db_Expr('SUM(q.complained_at IS NOT NULL)'),
            'last_open'  => new Zend_Db_Expr('MAX(IF(q.open_count > q.machine_open_count, q.opened_at, NULL))'),
            'last_click' => new Zend_Db_Expr('MAX(q.clicked_at)'),
        ]));

        $rows = $db->fetchAll((clone $base)
            ->columns(array_map(static fn ($c) => 'q.' . $c, self::QUEUE_COLUMNS))
            ->joinLeft(['c' => $this->_table('ysrtech_emailcampaigns/campaign')], 'c.campaign_id = q.campaign_id', ['campaign' => 'c.name', 'subject' => 'c.subject'])
            ->joinLeft(['a' => $this->_table('ysrtech_emailcampaigns/automation')], 'a.automation_id = q.automation_id', ['automation' => 'a.name'])
            ->order(new Zend_Db_Expr('COALESCE(q.sent_at, q.queued_at) DESC'))
            ->limit($this->_limit($args)));

        $clicks = [];
        $ids    = array_map('intval', array_column($rows, 'queue_id'));
        if ($ids) {
            foreach ($db->fetchAll($db->select()->from($this->_table('ysrtech_emailcampaigns/link_click'), ['queue_id', 'url', 'clicks'])->where('queue_id IN (?)', $ids)) as $c) {
                $clicks[(int) $c['queue_id']][] = ['url' => Mage::getSingleton('ysrtech_adminassistant/sanitizer')->quote((string) $c['url']), 'clicks' => (int) $c['clicks']];
            }
        }

        $messages = array_map(fn ($q) => [
            'email_was'  => $q['campaign_id'] ? 'campaign: ' . $q['campaign'] : ($q['automation_id'] ? 'automation: ' . $q['automation'] . ($q['step_order'] !== null ? ' (step ' . ((int) $q['step_order'] + 1) . ')' : '') : 'other'),
            'subject'    => $q['subject'],
        ] + $this->_outcome($q) + array_filter([
            'send_error'    => $q['status'] === 'failed' ? YSRTech_AdminAssistant_Model_Sanitizer::quote((string) $q['error_message']) : null,
            'links_clicked' => $clicks[(int) $q['queue_id']] ?? null,
        ]), $rows);

        $suppression = $db->fetchRow($db->select()->from($this->_table('ysrtech_emailcampaigns/suppression'), ['reason', 'source', 'detail', 'created_at'])->where('email = ?', $email)->limit(1));
        $subscriber  = $db->fetchRow($db->select()->from($this->_table('newsletter/subscriber'), ['subscriber_status', 'change_status_at'])->where('subscriber_email = ?', $email)->limit(1));
        $statuses    = [1 => 'subscribed', 2 => 'not active', 3 => 'unsubscribed', 4 => 'awaiting confirmation'];
        $reasons     = ['hard_bounce' => 'hard bounce (the address does not exist or refuses mail)', 'complaint' => 'marked an email as spam', 'unsubscribe' => 'unsubscribed', 'manual' => 'added by hand'];

        return ['rows' => count($messages), 'data' => [
            'email'       => $email,
            'newsletter'  => $subscriber ? ($statuses[(int) $subscriber['subscriber_status']] ?? 'unknown') . ' since ' . self::local($subscriber['change_status_at']) : 'not a newsletter subscriber',
            'suppressed'  => $suppression ? [
                'why'    => $reasons[$suppression['reason']] ?? $suppression['reason'],
                'source' => $suppression['source'],
                'detail' => YSRTech_AdminAssistant_Model_Sanitizer::quote($suppression['detail']),
                'since'  => self::local($suppression['created_at']),
                'effect' => 'Email Campaigns sends this address nothing while it is suppressed.',
            ] : 'no',
            'totals'      => [
                'messages'   => (int) $totals['messages'],
                'sent'       => (int) $totals['sent'],
                'opened'     => (int) $totals['opened'],
                'clicked'    => (int) $totals['clicked'],
                'bounced'    => (int) $totals['bounced'],
                'complained' => (int) $totals['complained'],
                'last_open'  => self::local($totals['last_open']),
                'last_click' => self::local($totals['last_click']),
            ],
            'about'        => 'Real opens leave out the automatic opens mail scanners make. Opens can be undercounted: some mail apps block tracking images.',
            'messages'    => $messages,
        ]];
    }

    private function _findCampaign(array $args): array
    {
        $db     = $this->_read();
        $select = $db->select()->from($this->_table('ysrtech_emailcampaigns/campaign'), ['campaign_id', 'name', 'subject', 'sent_at']);

        if (!empty($args['campaign_id'])) {
            $select->where('campaign_id = ?', (int) $args['campaign_id']);
        } elseif (!empty($args['name'])) {
            $select->where('name LIKE ? OR subject LIKE ?', $this->_like((string) $args['name']))->order(new Zend_Db_Expr('COALESCE(sent_at, created_at) DESC'));
        } else {
            $select->where('sent_at IS NOT NULL')->order('sent_at DESC');
        }

        $c = $db->fetchRow($select->limit(1));

        if (!$c) {
            Mage::throwException('No campaign found.');
        }

        return $c;
    }

    private function _recipients(array $args): array
    {
        $db     = $this->_read();
        $c      = $this->_findCampaign($args);
        $filter = (string) ($args['filter'] ?? 'all');
        $select = $db->select()->from(['q' => $this->_table('ysrtech_emailcampaigns/queue')], [])->where('q.campaign_id = ?', (int) $c['campaign_id']);

        $conditions = [
            'opened'     => "q.status = 'sent' AND q.bounced_at IS NULL AND q.open_count > q.machine_open_count",
            'clicked'    => 'q.click_count > 0',
            'not_opened' => "q.status = 'sent' AND q.bounced_at IS NULL AND q.open_count <= q.machine_open_count",
            'bounced'    => 'q.bounced_at IS NOT NULL',
            'complained' => 'q.complained_at IS NOT NULL',
            'failed'     => "q.status = 'failed'",
        ];

        if (isset($conditions[$filter])) {
            $select->where($conditions[$filter]);
        } else {
            $filter = 'all';
        }

        $matched = (int) $db->fetchOne((clone $select)->columns(new Zend_Db_Expr('COUNT(*)')));
        $order   = ['opened' => 'q.opened_at DESC', 'clicked' => 'q.clicked_at DESC', 'bounced' => 'q.bounced_at DESC', 'complained' => 'q.complained_at DESC'][$filter] ?? 'q.email ASC';
        $rows    = $db->fetchAll($select->columns(array_map(static fn ($col) => 'q.' . $col, self::QUEUE_COLUMNS))->order($order)->limit($this->_limit($args, 50)));

        return ['rows' => count($rows), 'data' => [
            'campaign'   => $c['name'],
            'campaign_id'=> (int) $c['campaign_id'],
            'sent'       => self::local($c['sent_at']),
            'filter'     => $filter,
            'matched'    => $matched,
            'listed'     => count($rows),
            'recipients' => array_map(fn ($q) => ['email' => $q['email']] + $this->_outcome($q)
                + ($q['status'] === 'failed' ? ['send_error' => YSRTech_AdminAssistant_Model_Sanitizer::quote((string) $q['error_message'])] : []), $rows),
        ]];
    }

    private function _automations(array $args): array
    {
        $db    = $this->_read();
        $range = self::range($args, 'last_30_days');
        $one   = !empty($args['automation_id']) ? (int) $args['automation_id'] : null;

        $list = $db->select()->from($this->_table('ysrtech_emailcampaigns/automation'), ['automation_id', 'name', 'event', 'order_status', 'is_active', 'active_from', 'active_to']);
        if ($one) {
            $list->where('automation_id = ?', $one);
        }
        $automations = $db->fetchAll($list->order('name ASC')->limit(100));

        if ($one && !$automations) {
            Mage::throwException('No automation with that id.');
        }

        $stats = $db->select()
            ->from(['q' => $this->_table('ysrtech_emailcampaigns/queue')], [
                'automation_id' => 'q.automation_id',
                'sent'       => new Zend_Db_Expr("SUM(q.status = 'sent')"),
                'waiting'    => new Zend_Db_Expr("SUM(q.status IN ('pending', 'processing'))"),
                'failed'     => new Zend_Db_Expr("SUM(q.status = 'failed')"),
                'delivered'  => new Zend_Db_Expr("SUM(q.status = 'sent' AND q.bounced_at IS NULL)"),
                'opened'     => new Zend_Db_Expr("SUM(q.status = 'sent' AND q.bounced_at IS NULL AND q.open_count > q.machine_open_count)"),
                'clicked'    => new Zend_Db_Expr('SUM(q.click_count > 0)'),
                'bounced'    => new Zend_Db_Expr('SUM(q.bounced_at IS NOT NULL)'),
                'complained' => new Zend_Db_Expr('SUM(q.complained_at IS NOT NULL)'),
            ])
            ->where('q.automation_id IS NOT NULL');
        $this->_applyRange($stats, 'COALESCE(q.sent_at, q.queued_at)', $range);

        if ($one) {
            $stats->where('q.automation_id = ?', $one)->columns(['step' => 'q.step_order'])->group('q.step_order');
        } else {
            $stats->group('q.automation_id');
        }

        $rows  = $db->fetchAll($stats);
        $rate  = static fn ($n, $d) => $d ? round($n / $d * 100, 1) . '%' : null;
        $shape = static fn ($r) => [
            'sent'         => (int) $r['sent'],
            'still_queued' => (int) $r['waiting'],
            'failed'       => (int) $r['failed'],
            'delivered'    => (int) $r['delivered'],
            'real_opens'   => (int) $r['opened'],
            'open_rate'    => $rate((int) $r['opened'], (int) $r['delivered']),
            'clicks'       => (int) $r['clicked'],
            'click_rate'   => $rate((int) $r['clicked'], (int) $r['delivered']),
            'bounces'      => (int) $r['bounced'],
            'complaints'   => (int) $r['complained'],
        ];

        $money = [];
        try {
            $money = Mage::getSingleton('ysrtech_emailcampaigns/conversions')->grouped('automation_id', array_map('intval', array_column($automations, 'automation_id')));
        } catch (Throwable $e) {
            // Older versions: no revenue figures
        }

        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['automation_id']][] = $r;
        }

        $out = array_map(function ($a) use ($byId, $shape, $one, $money) {
            $id   = (int) $a['automation_id'];
            $item = [
                'automation_id' => $id,
                'name'          => $a['name'],
                'trigger'       => $a['event'] . ($a['order_status'] ? ' (' . $a['order_status'] . ')' : ''),
                'active'        => (bool) $a['is_active'],
                'revenue_credited_all_time' => $money[$id]['label'] ?? null,
                'orders_credited_all_time'  => $money[$id]['orders'] ?? 0,
            ];

            if ($one) {
                $item['steps'] = array_map(static fn ($r) => ['step' => $r['step'] !== null ? (int) $r['step'] + 1 : null] + $shape($r), $byId[$id] ?? []);
            } else {
                $item += $shape($byId[$id][0] ?? array_fill_keys(['sent', 'waiting', 'failed', 'delivered', 'opened', 'clicked', 'bounced', 'complained'], 0));
            }

            return $item;
        }, $automations);

        return ['rows' => count($out), 'data' => [
            'period'      => $range,
            'about'        => 'Sends, opens and clicks are for the period; credited orders and revenue are all time.',
            'automations' => $out,
        ]];
    }
}

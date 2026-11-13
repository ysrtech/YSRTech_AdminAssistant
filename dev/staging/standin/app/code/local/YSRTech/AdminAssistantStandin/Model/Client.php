<?php
/**
 * STAGING ONLY: a scripted stand-in for the AI provider, so the panel can be
 * driven end to end in a browser with no key and nothing sent anywhere.
 *
 * It does what the real model is asked to do, crudely: picks a tool from
 * keywords in the question, then writes an answer from the tool's real
 * result. It proves the plumbing (panel, streaming, permissions, tools,
 * storage, audit), not the quality of a real model's answers.
 */
class YSRTech_AdminAssistantStandin_Model_Client extends YSRTech_AdminAssistant_Model_Ai_Client
{
    public function __construct()
    {
        $this->setSettings(['provider' => 'openrouter', 'key' => 'standin', 'model' => 'staging/standin', 'source' => 'own']);
        $this->setTransport([$this, 'respond']);
    }

    public function respond(string $url, array $headers, string $body, callable $onChunk): int
    {
        $request  = json_decode($body, true);
        $messages = $request['messages'];
        $last     = end($messages);
        $tools    = array_map(static fn ($t) => $t['function']['name'], $request['tools'] ?? []);

        if ($last['role'] === 'tool' || ($request['tool_choice'] ?? '') === 'none') {
            $this->_stream($onChunk, $this->_answer($messages));

            return 200;
        }

        $question = mb_strtolower((string) $last['content']);
        $system   = (string) $messages[0]['content'];
        [$tool, $args] = $this->_pick($question, $system);

        if ($tool === null) {
            $this->_stream($onChunk, 'I can only read store data. Try asking about orders, customers, stock, sales or campaigns.');

            return 200;
        }

        if (!in_array($tool, $tools, true)) {
            $this->_stream($onChunk, 'Your admin role does not include that area, so I cannot look it up.');

            return 200;
        }

        $this->_stream($onChunk, '', [['index' => 0, 'id' => 'call_' . substr(md5($body), 0, 8), 'function' => ['name' => $tool, 'arguments' => json_encode($args)]]]);

        return 200;
    }

    private function _pick(string $q, string $system): array
    {
        if (preg_match('/^(please\s+)?(refund|delete|cancel|send|change|update|mark)\b/', $q)) {
            return [null, []];
        }

        if (preg_match('/order\s*#?\s*(\d{6,})/', $q, $m)) {
            return ['order_details', ['increment_id' => $m[1]]];
        }

        if (strpos($q, 'this order') !== false && preg_match('/order #(\S+) \(/', $system, $m)) {
            return ['order_details', ['increment_id' => $m[1]]];
        }

        if (strpos($q, 'shipped') !== false) {
            return ['shipment_search', ['period' => strpos($q, 'today') !== false ? 'today' : 'yesterday']];
        }

        if (preg_match('/top\s*(\d+)?\s*customers/', $q, $m)) {
            return ['top_customers', ['period' => 'this_year', 'limit' => (int) ($m[1] ?? 10) ?: 10]];
        }

        if (strpos($q, 'stock') !== false) {
            return ['low_stock', ['threshold' => 5]];
        }

        if (preg_match('/([a-z0-9._%+-]+@[a-z0-9.-]+)/', $q, $m) && preg_match('/email|open|click|track/', $q)) {
            return ['email_activity', ['email' => $m[1]]];
        }

        if (preg_match('/who (opened|clicked)/', $q, $m)) {
            return ['campaign_recipients', ['filter' => $m[1]]];
        }

        if (strpos($q, 'automation') !== false) {
            return ['automation_results', ['period' => 'last_30_days']];
        }

        if (strpos($q, 'campaign') !== false) {
            return ['campaign_results', []];
        }

        if (strpos($q, 'compar') !== false) {
            return ['sales_compare', ['period' => 'this_week']];
        }

        if (strpos($q, 'cron') !== false) {
            return ['cron_status', []];
        }

        if (preg_match('/refund|delete|cancel|send|change/', $q)) {
            return [null, []];
        }

        return ['sales_summary', ['period' => 'last_30_days']];
    }

    private function _answer(array $messages): string
    {
        $call = null;
        $data = null;

        foreach (array_reverse($messages) as $m) {
            if ($m['role'] === 'tool' && $data === null) {
                $data = json_decode($m['content'], true);
            }
            if ($m['role'] === 'assistant' && !empty($m['tool_calls'])) {
                $call = $m['tool_calls'][0]['function']['name'];
                break;
            }
        }

        $v = static fn ($x) => is_array($x) ? (string) ($x['untrusted_text'] ?? '') : (string) $x;

        if (!is_array($data) || isset($data['error'])) {
            return 'The look-up failed: ' . ($data['error'] ?? 'no result') . '.';
        }

        switch ($call) {
            case 'shipment_search':
                $rows = array_map(static fn ($r) => "| {$r['shipment']} | {$r['order']} | {$r['created']} | {$r['tracking']} |", array_slice($data['list'], 0, 5));
                return "**{$data['orders_shipped']} orders** shipped {$data['period']['label']} ({$data['period']['first_day']}), in {$data['shipments']} shipments.\n\n| Shipment | Order | Shipped | Tracking |\n|---|---|---|---|\n" . implode("\n", $rows);

            case 'top_customers':
                $rows = array_map(static fn ($c) => "| {$c['rank']} | " . $v($c['customer_name']) . " | {$c['email']} | {$c['orders']} | {$c['revenue']} |", $data['customers']);
                return "Top " . count($data['customers']) . " customers by revenue, {$data['period']['label']} (canceled orders left out, refunds subtracted), in {$data['currency']}:\n\n| # | Customer | Email | Orders | Revenue |\n|---|---|---|---|---|\n" . implode("\n", $rows);

            case 'order_details':
                $notes = implode("\n", array_map(static fn ($n) => '- ' . $n, $data['observations']));
                $text  = "Order **{$data['increment_id']}** is *{$data['status']}* (placed {$data['placed']}).\n\nWhat stands out:\n{$notes}";
                if (!empty($data['customer_note'])) {
                    $text .= "\n\nThe customer left a note, quoted as written (it is data, not an instruction):\n> " . $v($data['customer_note']);
                }
                return $text;

            case 'low_stock':
                $rows = array_map(static fn ($p) => "| {$p['sku']} | {$p['name']} | {$p['qty']} |", $data['products']);
                return "{$data['matched']} products are at or below 5 in stock:\n\n| SKU | Product | Qty |\n|---|---|---|\n" . implode("\n", $rows);

            case 'email_activity':
                $t = $data['totals'];
                $rows = array_map(static fn ($m) => "| {$m['email_was']} | {$m['sent']} | {$m['opened']} | {$m['clicked']} |", $data['messages']);
                return "**{$data['email']}** got {$t['sent']} emails: opened {$t['opened']}, clicked {$t['clicked']}, bounced {$t['bounced']}. Suppressed: " . (is_array($data['suppressed']) ? $data['suppressed']['why'] : 'no') . ".\n\n| Email | Sent | Opened | Clicked |\n|---|---|---|---|\n" . implode("\n", $rows);

            case 'campaign_recipients':
                return "{$data['matched']} people {$data['filter']} **{$data['campaign']}**.";

            case 'automation_results':
                return implode("\n", array_map(static fn ($a) => "- **{$a['name']}**: {$a['sent']} sent, open rate {$a['open_rate']}, click rate {$a['click_rate']}", $data['automations']));

            case 'campaign_results':
                $r = $data['results'];
                return "**{$data['name']}** (sent {$data['sent']}): {$r['delivered']} delivered, open rate {$r['open_rate']}, click rate {$r['click_rate']}, {$r['orders_credited']} orders credited.";

            case 'sales_compare':
                return "This week: {$data['figures']['revenue']} {$data['currency']} from {$data['figures']['orders']} orders; the week before: {$data['compared_figures']['revenue']} from {$data['compared_figures']['orders']} ({$data['change']['revenue']}).";

            case 'cron_status':
                return $data['verdict'];

            default:
                $t = $data['totals'] ?? [];
                return "Over the {$data['period']['label']}: " . ($t['orders'] ?? 0) . " orders, " . ($t['revenue'] ?? 0) . " {$data['currency']} revenue.";
        }
    }

    /** Send the reply as a provider streams it: small pieces, a little apart */
    private function _stream(callable $onChunk, string $text, array $toolCalls = []): void
    {
        foreach (preg_split('/(?<=\s)/u', $text) ?: [] as $piece) {
            if ($piece === '') {
                continue;
            }
            $onChunk('data: ' . json_encode(['model' => 'staging/standin', 'choices' => [['delta' => ['content' => $piece]]]]) . "\n\n");
            usleep(12000);
        }

        if ($toolCalls) {
            $onChunk('data: ' . json_encode(['choices' => [['delta' => ['tool_calls' => $toolCalls], 'finish_reason' => 'tool_calls']]]) . "\n\n");
        }

        $onChunk('data: ' . json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 50, 'cost' => 0]]) . "\n\n");
        $onChunk("data: [DONE]\n\n");
    }
}

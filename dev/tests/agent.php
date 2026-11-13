<?php
/**
 * The question-to-answer loop with a scripted stand-in for the provider:
 * nothing is sent anywhere. Checks streaming, tool calls arriving in
 * fragments, what is sent to the model, conversation history, the audit
 * log, the look-up limit, errors, and the privacy switch.
 *
 *   php <module>/dev/tests/agent.php      (from the OpenMage root)
 */
require 'app/Mage.php';
Mage::app('admin');

$pass = $fail = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail) {
    $ok ? $pass++ : $fail++;
    echo ($ok ? "  ok   " : "  FAIL ") . $name . ($ok || $detail === '' ? '' : "\n         " . mb_substr($detail, 0, 700)) . "\n";
};

$config = Mage::getModel('core/config');
$saved  = ['general/enabled' => Mage::getStoreConfig('ysrtech_adminassistant/general/enabled', 0), 'connection/max_calls_per_hour' => Mage::getStoreConfig('ysrtech_adminassistant/connection/max_calls_per_hour', 0), 'behaviour/max_rounds' => Mage::getStoreConfig('ysrtech_adminassistant/behaviour/max_rounds', 0)];
$set    = static function (string $path, $value) use ($config) {
    $config->saveConfig('ysrtech_adminassistant/' . $path, $value);
    Mage::getConfig()->reinit();
    Mage::app()->reinitStores();
};

$user = Mage::getModel('admin/user')->loadByUsername('admin');
$db   = Mage::getSingleton('core/resource')->getConnection('core_read');

/** SSE text for one streamed reply, cut into small pieces as a provider sends it */
$sse = static function (array $chunks): array {
    $out = [];
    foreach ($chunks as $c) {
        $out[] = 'data: ' . json_encode($c) . "\n\n";
    }
    $out[] = "data: [DONE]\n\n";
    // Split across event boundaries, the way TCP delivers it
    $joined = implode('', $out);

    return str_split($joined, 37);
};

/** A stand-in provider that plays the given replies in order and records requests */
$standin = static function (array $replies, array &$requests, int $status = 200) {
    return static function (string $url, array $headers, string $body, callable $onChunk) use (&$replies, &$requests, $status) {
        $requests[] = json_decode($body, true);
        foreach (array_shift($replies) ?? [] as $piece) {
            $onChunk($piece);
        }

        return $status;
    };
};

$agentWith = static function (callable $transport, ?callable $acl = null) {
    $client = Mage::getModel('ysrtech_adminassistant/ai_client')
        ->setSettings(['provider' => 'openrouter', 'key' => 'test-key', 'model' => 'test/standin', 'source' => 'own'])
        ->setTransport($transport);
    $tools = Mage::getModel('ysrtech_adminassistant/tools')->setAclCheck($acl ?? static fn () => true);

    return Mage::getModel('ysrtech_adminassistant/agent')->setClient($client)->setTools($tools);
};

echo "Stream parsing\n";
$text = '';
$p = new YSRTech_AdminAssistant_Model_Ai_Stream(static function ($d) use (&$text) { $text .= $d; });
foreach ($sse([
    ['model' => 'm', 'choices' => [['delta' => ['content' => 'Let me ']]]],
    ['choices' => [['delta' => ['content' => 'check.', 'tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'order_', 'arguments' => '{"incr']]]]]]],
    ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['name' => 'details', 'arguments' => 'ement_id":"1"}']]]]]]],
    ['choices' => [['delta' => ['tool_calls' => [['index' => 1, 'id' => 'call_2', 'function' => ['name' => 'cron_status', 'arguments' => '{}']]]], 'finish_reason' => 'tool_calls']]],
    ['choices' => [], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'cost' => 0.0001]],
]) as $piece) {
    $p->feed($piece);
}
$p->finish();
$r = $p->result();
$check('text streams as it comes', $text === 'Let me check.' && $r['text'] === 'Let me check.');
$check('a tool call in fragments is put back together', $r['tool_calls'][0]['name'] === 'order_details' && $r['tool_calls'][0]['arguments'] === '{"increment_id":"1"}', json_encode($r['tool_calls']));
$check('two tool calls in one reply', count($r['tool_calls']) === 2 && $r['tool_calls'][1]['id'] === 'call_2');
$check('usage and cost are read', $r['usage']['prompt'] === 10 && $r['usage']['cost'] === 0.0001);
$p = new YSRTech_AdminAssistant_Model_Ai_Stream();
$p->feed('{"choices":[{"message":{"content":"plain"},"finish_reason":"stop"}],"usage":{"prompt_tokens":1,"completion_tokens":1}}');
$p->finish();
$check('a reply that is not streamed is read too', $p->result()['text'] === 'plain');
$p = new YSRTech_AdminAssistant_Model_Ai_Stream();
$p->feed(": OPENROUTER PROCESSING\n\ndata: {\"error\":{\"message\":\"overloaded\"}}\n\n");
$p->finish();
$check('an error in the stream is caught', $p->error() === 'overloaded');

echo "\nHow-to questions and store procedures\n";
$config->saveConfig('ysrtech_adminassistant/behaviour/procedures', 'Refunds over $200 need the owner\'s approval first.');
Mage::getConfig()->reinit();
$prompt = Mage::getModel('ysrtech_adminassistant/agent')->setTools(Mage::getModel('ysrtech_adminassistant/tools')->setAclCheck(static fn () => true))->systemPrompt();
$check('the prompt says how to answer how-to questions', stripos($prompt, 'how-to questions') !== false && stripos($prompt, 'settings_search') !== false);
$check('the store procedures are in the prompt, below the rules', strpos($prompt, 'Refunds over $200') > strpos($prompt, 'You can only read'));
$config->deleteConfig('ysrtech_adminassistant/behaviour/procedures');
Mage::getConfig()->reinit();

echo "\nThe privacy switch\n";
$set('general/enabled', 0);
$requests = [];
$events   = [];
$agentWith($standin([], $requests))->ask($user, null, 'How many orders today?', [], static function ($e, $d) use (&$events) { $events[] = [$e, $d]; });
$check('while off, the question is refused with the reason', ($events[0][0] ?? '') === 'error' && stripos($events[0][1]['message'], 'switched off') !== false, json_encode($events));
$check('and nothing at all is sent to the provider', $requests === []);

$set('general/enabled', 1);
$set('connection/max_calls_per_hour', 1000);
$set('behaviour/max_rounds', 8);

echo "\nA question that needs a look-up\n";
$requests = [];
$events   = [];
$agent    = $agentWith($standin([
    $sse([
        ['choices' => [['delta' => ['content' => 'Checking. ']]]],
        ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'c1', 'function' => ['name' => 'order_details', 'arguments' => '{"increment_id":']]]]]]],
        ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '"100045123"}']]]], 'finish_reason' => 'tool_calls']]],
        ['choices' => [], 'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 20]],
    ]),
    $sse([
        ['choices' => [['delta' => ['content' => 'Order **100045123** was invoiced ']]]],
        ['choices' => [['delta' => ['content' => '6 days ago and has not shipped.'], 'finish_reason' => 'stop']]],
        ['choices' => [], 'usage' => ['prompt_tokens' => 2400, 'completion_tokens' => 30, 'cost' => 0.002]],
    ]),
], $requests));
$agent->ask($user, null, 'What\'s wrong with this order?', ['type' => 'order', 'id' => (int) $db->fetchOne("SELECT entity_id FROM sales_flat_order WHERE increment_id = '100045123'"), 'page' => 'sales_order/view'],
    static function ($e, $d) use (&$events) { $events[] = [$e, $d]; });

$kinds = array_column($events, 0);
$check('events: conversation, text, tool, tool_done, text, done', $kinds[0] === 'conversation' && in_array('tool', $kinds, true) && in_array('tool_done', $kinds, true) && end($kinds) === 'done', implode(',', $kinds));
$answer = implode('', array_map(static fn ($e) => $e[1]['delta'] ?? '', array_filter($events, static fn ($e) => $e[0] === 'text')));
$check('the answer streamed in pieces', substr_count(implode(',', $kinds), 'text') >= 3 && strpos($answer, 'has not shipped') !== false, $answer);
$toolEvent = array_values(array_filter($events, static fn ($e) => $e[0] === 'tool'))[0][1] ?? [];
$check('the panel is told what is being looked up', $toolEvent['name'] === 'order_details' && strpos($toolEvent['summary'], '100045123') !== false, json_encode($toolEvent));

$first = $requests[0];
$check('the request carries stream, tools and the model', $first['stream'] === true && $first['model'] === 'test/standin' && count($first['tools']) > 15);
$system = $first['messages'][0]['content'];
$check('the system prompt states the read-only and untrusted-text rules', stripos($system, 'You can only read') !== false && stripos($system, 'untrusted_text') !== false);
$check('the page context is in the system prompt, from the database', strpos($system, 'order #100045123') !== false, $system);
$second = $requests[1];
$toolMsg = array_values(array_filter($second['messages'], static fn ($m) => $m['role'] === 'tool'))[0] ?? [];
$check('the tool result goes back to the model, paired with its call', ($toolMsg['tool_call_id'] ?? '') === 'c1' && strpos($toolMsg['content'], 'observations') !== false);
$check('and carries no payment data', strpos($toolMsg['content'], '4242') === false && strpos($toolMsg['content'], 'tok_FAKE') === false);

$cid = (int) $events[0][1]['id'];
$messages = $db->fetchCol('SELECT role FROM ysrtech_adminassistant_message WHERE conversation_id = ? ORDER BY message_id', [$cid]);
$check('the conversation is stored: question, call, result, answer', $messages === ['user', 'assistant', 'tool', 'assistant'], implode(',', $messages));
$audit = $db->fetchAll('SELECT kind, tool, rows_returned, prompt_tokens, cost_usd FROM ysrtech_adminassistant_audit WHERE conversation_id = ? ORDER BY audit_id', [$cid]);
$check('the audit log has the question, the look-up and both AI requests', array_column($audit, 'kind') === ['question', 'model', 'tool', 'model'], json_encode($audit));
$check('with tokens and cost', (int) $audit[3]['prompt_tokens'] === 2400 && (float) $audit[3]['cost_usd'] === 0.002);

echo "\nA follow-up in the same conversation\n";
$requests = [];
$events   = [];
$agentWith($standin([$sse([['choices' => [['delta' => ['content' => 'Yes.'], 'finish_reason' => 'stop']]]])], $requests))
    ->ask($user, $cid, 'Did the customer get notified?', [], static function ($e, $d) use (&$events) { $events[] = [$e, $d]; });
$roles = array_column($requests[0]['messages'], 'role');
$check('the history goes back in order, tool calls paired with results', $roles === ['system', 'user', 'assistant', 'tool', 'assistant', 'user'], implode(',', $roles));
$check('the same conversation continues', (int) $events[0][1]['id'] === $cid);
$transcript = Mage::getModel('ysrtech_adminassistant/conversation')->transcript($cid);
$check('the transcript for the panel shows the look-up, in the order it happened', array_column($transcript, 'role') === ['user', 'assistant', 'tool', 'assistant', 'user', 'assistant'], json_encode($transcript));

echo "\nSomeone else's conversation\n";
$other = Mage::getModel('ysrtech_adminassistant/conversation')->create(999999, 'not yours');
$requests = [];
$events   = [];
$agentWith($standin([$sse([['choices' => [['delta' => ['content' => 'Hi.'], 'finish_reason' => 'stop']]]])], $requests))
    ->ask($user, $other, 'hello', [], static function ($e, $d) use (&$events) { $events[] = [$e, $d]; });
$check('is not continued: a new one is started', (int) $events[0][1]['id'] !== $other && count($requests[0]['messages']) === 2);
$check('and cannot be loaded or deleted by this user', Mage::getModel('ysrtech_adminassistant/conversation')->load($other, (int) $user->getId()) === null
    && !Mage::getModel('ysrtech_adminassistant/conversation')->delete($other, (int) $user->getId()));

echo "\nThe look-up limit\n";
$set('behaviour/max_rounds', 2);
$loop     = $sse([['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'x', 'function' => ['name' => 'cron_status', 'arguments' => '{}']]]], 'finish_reason' => 'tool_calls']]]]);
$final    = $sse([['choices' => [['delta' => ['content' => 'Here is what I found.'], 'finish_reason' => 'stop']]]]);
$requests = [];
$events   = [];
$agentWith($standin([$loop, $loop, $final], $requests))->ask($user, null, 'loop', [], static function ($e, $d) use (&$events) { $events[] = [$e, $d]; });
$check('after the limit the model must answer: tools described, not callable', count($requests) === 3 && ($requests[2]['tool_choice'] ?? '') === 'none' && !empty($requests[2]['tools']));
$check('and the answer arrives', end($events)[0] === 'done');
$set('behaviour/max_rounds', 8);

echo "\nWhen the provider fails\n";
$requests = [];
$events   = [];
$agentWith($standin([['{"error":{"message":"bad key"}}']], $requests, 401))->ask($user, null, 'hi', [], static function ($e, $d) use (&$events) { $events[] = [$e, $d]; });
$err = array_values(array_filter($events, static fn ($e) => $e[0] === 'error'))[0][1]['message'] ?? '';
$check('a refused key is explained', stripos($err, 'refused the key') !== false, $err);
$requests = [];
$events   = [];
$agentWith($standin([['{"error":{"message":"No endpoints found that support tool use"}}']], $requests, 404))->ask($user, null, 'hi', [], static function ($e, $d) use (&$events) { $events[] = [$e, $d]; });
$err = array_values(array_filter($events, static fn ($e) => $e[0] === 'error'))[0][1]['message'] ?? '';
$check('a model without tool calling is explained', stripos($err, 'tool calling') !== false, $err);

echo "\nThe hourly limit\n";
$set('connection/max_calls_per_hour', 1);
$requests = [];
$events   = [];
$agentWith($standin([], $requests))->ask($user, null, 'hi', [], static function ($e, $d) use (&$events) { $events[] = [$e, $d]; });
$err = array_values(array_filter($events, static fn ($e) => $e[0] === 'error'))[0][1]['message'] ?? '';
$check('over the limit, nothing is sent and the reason is given', $requests === [] && stripos($err, 'hourly limit') !== false, $err);

echo "\nA role without the area asked about\n";
$set('connection/max_calls_per_hour', 1000);
$requests = [];
$agentWith($standin([$sse([['choices' => [['delta' => ['content' => 'No.'], 'finish_reason' => 'stop']]]])], $requests), static fn ($r) => strpos($r, 'customers') === false)
    ->ask($user, null, 'Top customers?', [], static function () {});
$names = array_map(static fn ($t) => $t['function']['name'], $requests[0]['tools']);
$check('customer tools are not offered', !in_array('top_customers', $names, true) && in_array('order_search', $names, true));
$check('and the prompt says the role does not include them', stripos($requests[0]['messages'][0]['content'], 'does not allow these areas: customers') !== false);

// ---- restore
foreach ($saved as $path => $value) {
    $value === null ? $config->deleteConfig('ysrtech_adminassistant/' . $path) : $config->saveConfig('ysrtech_adminassistant/' . $path, $value);
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);

<?php
/**
 * The assistant's connection to a language model: chat completions with
 * tools, streamed.
 *
 * Built on Email Campaigns' AI client and its settings. That client sends
 * one message and waits for one reply; the assistant needs the model to be
 * able to ask for look-ups and to stream its answer, so this speaks the same
 * OpenAI-style format (OpenRouter and OpenAI both accept it) with tools and
 * stream turned on. The key, provider and model come from Email Campaigns'
 * AI settings when that module is installed and has them, or from this
 * module's own settings.
 */
class YSRTech_AdminAssistant_Model_Ai_Client
{
    public const ENDPOINTS = [
        'openrouter' => 'https://openrouter.ai/api/v1/chat/completions',
        'openai'     => 'https://api.openai.com/v1/chat/completions',
    ];

    /**
     * @var callable|null fn(string $url, array $headers, string $body, callable $onChunk): int
     *      Feeds the raw response body to $onChunk in pieces and returns the HTTP status.
     */
    private $_transport;

    /** @var array{provider:string, key:string, model:string, source:string}|null */
    private $_settings;

    /**
     * Replace the HTTP call. For tests, which must never reach a provider.
     */
    public function setTransport(?callable $transport): self
    {
        $this->_transport = $transport;

        return $this;
    }

    /**
     * Fix the connection settings rather than reading them. For tests.
     */
    public function setSettings(?array $settings): self
    {
        $this->_settings = $settings;

        return $this;
    }

    /**
     * @return array{provider:string, key:string, model:string, source:string}
     */
    public function settings(): array
    {
        if ($this->_settings !== null) {
            return $this->_settings;
        }

        $h = Mage::helper('ysrtech_adminassistant');

        if ($h->getConfig('connection/source') !== 'own' && $h->isEmailCampaignsInstalled()) {
            $key   = $h->decrypt((string) Mage::getStoreConfig('ysrtech_emailcampaigns/ai/api_key', 0));
            $model = self::cleanModel((string) Mage::getStoreConfig('ysrtech_emailcampaigns/ai/model', 0));

            if ($key !== '' && $model !== '') {
                return [
                    'provider' => self::cleanProvider((string) Mage::getStoreConfig('ysrtech_emailcampaigns/ai/provider', 0)),
                    'key'      => $key,
                    'model'    => $model,
                    'source'   => 'emailcampaigns',
                ];
            }
        }

        return [
            'provider' => self::cleanProvider((string) $h->getConfig('connection/provider')),
            'key'      => $h->getSecret('connection/api_key'),
            'model'    => self::cleanModel((string) $h->getConfig('connection/model')),
            'source'   => 'own',
        ];
    }

    public static function cleanProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));

        return isset(self::ENDPOINTS[$provider]) ? $provider : 'openrouter';
    }

    public static function cleanModel(string $model): string
    {
        $model = trim($model);

        return preg_match('#^[A-Za-z0-9._:/-]{2,120}$#', $model) ? $model : '';
    }

    public function model(): string
    {
        return $this->settings()['model'];
    }

    /**
     * Why the assistant cannot answer, in words for the person asking; null
     * when it can.
     */
    public function unavailable(): ?string
    {
        $h = Mage::helper('ysrtech_adminassistant');

        if (!$h->isEnabled()) {
            return $h->__('The AI Assistant is switched off. It can be turned on under System > Configuration > YSRTech > AI Assistant, after reading what it sends.');
        }

        $s = $this->settings();

        if ($s['key'] === '' || $s['model'] === '') {
            return $h->__('The AI Assistant has no key or model yet. Add them under System > Configuration > YSRTech > AI Assistant, or in Email Campaigns\' AI settings.');
        }

        return null;
    }

    public function callsLastHour(): int
    {
        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_read');

        return (int) $adapter->fetchOne(
            $adapter->select()
                ->from($resource->getTableName('ysrtech_adminassistant/audit'), [new Zend_Db_Expr('COUNT(*)')])
                ->where('kind = ?', 'model')
                ->where('created_at >= ?', gmdate('Y-m-d H:i:s', time() - 3600))
        );
    }

    /**
     * One model turn.
     *
     * @param  array<int, array>  $messages In the chat-completions format
     * @param  array<int, array>  $tools    Function definitions; empty for none
     * @param  callable|null      $onText   fn(string $delta), called as the answer streams
     * @return array{text:string, tool_calls:array<int, array{id:string, name:string, arguments:string}>, finish:string, usage:array{prompt:int, completion:int, cost:float|null}, model:string}
     * @throws Mage_Core_Exception With a message fit to show
     */
    public function turn(array $messages, array $tools = [], ?callable $onText = null, array $options = []): array
    {
        $h = Mage::helper('ysrtech_adminassistant');

        if ($reason = $this->unavailable()) {
            Mage::throwException($reason);
        }

        $limit = max(1, (int) $h->getConfig('connection/max_calls_per_hour'));

        if (empty($options['skip_limit']) && $this->callsLastHour() >= $limit) {
            Mage::throwException($h->__('The hourly limit of %d AI requests has been reached. Try again later, or raise the limit in the AI Assistant settings.', $limit));
        }

        $s    = $this->settings();
        $body = [
            'model'       => $s['model'],
            'messages'    => $messages,
            'max_tokens'  => (int) ($options['max_tokens'] ?? 2000),
            'temperature' => (float) ($options['temperature'] ?? 0.2),
            'stream'      => true,
        ];

        if ($tools) {
            $body['tools']       = $tools;
            $body['tool_choice'] = ($options['tool_choice'] ?? 'auto') === 'none' ? 'none' : 'auto';
        }

        if ($s['provider'] === 'openrouter') {
            $body['usage'] = ['include' => true];
        } else {
            $body['stream_options'] = ['include_usage' => true];
        }

        $headers = ['Authorization: Bearer ' . $s['key'], 'Content-Type: application/json', 'Accept: text/event-stream'];

        if ($s['provider'] === 'openrouter') {
            // A fixed label for the provider's dashboard; the store's name is not theirs to have
            $headers[] = 'X-Title: Admin Assistant';
        }

        $parser  = new YSRTech_AdminAssistant_Model_Ai_Stream($onText);
        $started = microtime(true);
        $raw     = '';

        try {
            $status = $this->_send(self::ENDPOINTS[$s['provider']], $headers, (string) json_encode($body), static function (string $chunk) use ($parser, &$raw) {
                if (strlen($raw) < 4096) {
                    $raw .= $chunk; // kept for an error message, not for the answer
                }
                $parser->feed($chunk);
            });
        } catch (Throwable $e) {
            $this->_log($started, false, 0, 0, null, 'Connection: ' . $e->getMessage());
            Mage::throwException($h->__('The AI service did not answer. Try again in a minute.'));
        }

        $parser->finish();

        if ($status < 200 || $status >= 300 || $parser->error() !== null) {
            $json   = json_decode($raw, true);
            $detail = $parser->error() ?? (is_array($json['error'] ?? null) ? (string) ($json['error']['message'] ?? '') : '');
            $this->_log($started, false, 0, 0, null, 'HTTP ' . $status . ': ' . $detail);

            if ($status === 401 || $status === 403) {
                Mage::throwException($h->__('The AI provider refused the key. Check it in the AI settings.'));
            }

            if ($status === 400 || $status === 404) {
                Mage::throwException($h->__('The AI provider did not accept the request. The model may not support tool calling; check the model in the AI settings. (%s)', mb_substr($detail, 0, 160)));
            }

            Mage::throwException($status === 429
                ? $h->__('The AI service is busy or out of credit. Try again in a minute.')
                : $h->__('The AI service returned an error. Try again in a minute.'));
        }

        $result = $parser->result();
        $this->_log($started, true, $result['usage']['prompt'], $result['usage']['completion'], $result['usage']['cost'], null);
        $result['model'] = $result['model'] ?: $s['model'];

        return $result;
    }

    /**
     * A one-word round trip with no store data, for the settings page.
     *
     * @return array{ok:bool, message:string}
     */
    public function test(): array
    {
        $h = Mage::helper('ysrtech_adminassistant');

        try {
            $s     = $this->settings();
            $reply = $this->turn([['role' => 'user', 'content' => 'Reply with the single word: ready']], [], null, ['max_tokens' => 20, 'temperature' => 0, 'skip_limit' => true]);
        } catch (Mage_Core_Exception $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        return ['ok' => true, 'message' => $h->__(
            '%s answered with model %s, using %s.',
            $s['provider'] === 'openai' ? 'OpenAI' : 'OpenRouter',
            $reply['model'],
            $s['source'] === 'emailcampaigns' ? $h->__('Email Campaigns\' key') : $h->__('this module\'s key')
        )];
    }

    private function _send(string $url, array $headers, string $body, callable $onChunk): int
    {
        if ($this->_transport) {
            return (int) call_user_func($this->_transport, $url, $headers, $body, $onChunk);
        }

        // The key goes out with the request, so only ever to the addresses above
        if (!in_array($url, self::ENDPOINTS, true)) {
            throw new RuntimeException('Refusing to send an AI key to ' . parse_url($url, PHP_URL_HOST));
        }

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $data) use ($onChunk): int {
                $onChunk($data);

                return strlen($data);
            },
        ]);

        $ok     = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($ok === false) {
            throw new RuntimeException($error);
        }

        return $status;
    }

    private function _log(float $started, bool $ok, int $in, int $out, ?float $cost, ?string $error): void
    {
        Mage::getSingleton('ysrtech_adminassistant/audit')->model(
            $this->model(),
            $ok,
            $in,
            $out,
            $cost,
            (int) ((microtime(true) - $started) * 1000),
            $error
        );
    }
}

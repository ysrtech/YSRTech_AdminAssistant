<?php
/**
 * Reads a streamed chat-completions response (server-sent events) as it
 * arrives: passes text on as it comes, and puts tool calls back together
 * from the fragments they arrive in.
 *
 * A provider that does not stream, or answers with an error, sends one JSON
 * document instead of events; that is read too.
 */
class YSRTech_AdminAssistant_Model_Ai_Stream
{
    /** @var callable|null */
    private $_onText;

    private string $_buffer = '';

    private string $_plain = '';

    private bool $_sawEvent = false;

    private string $_text = '';

    /** @var array<int, array{id:string, name:string, arguments:string}> */
    private array $_calls = [];

    private string $_finish = '';

    private string $_model = '';

    private ?string $_error = null;

    /** @var array{prompt:int, completion:int, cost:float|null} */
    private array $_usage = ['prompt' => 0, 'completion' => 0, 'cost' => null];

    public function __construct(?callable $onText = null)
    {
        $this->_onText = $onText;
    }

    public function feed(string $chunk): void
    {
        $this->_buffer .= $chunk;

        while (($pos = strpos($this->_buffer, "\n")) !== false) {
            $line          = rtrim(substr($this->_buffer, 0, $pos), "\r");
            $this->_buffer = substr($this->_buffer, $pos + 1);
            $this->_line($line);
        }
    }

    public function finish(): void
    {
        if ($this->_buffer !== '') {
            $this->_line(rtrim($this->_buffer, "\r"));
            $this->_buffer = '';
        }

        if (!$this->_sawEvent && trim($this->_plain) !== '') {
            $json = json_decode($this->_plain, true);

            if (is_array($json)) {
                $this->_absorbWhole($json);
            }
        }
    }

    public function error(): ?string
    {
        return $this->_error;
    }

    /**
     * @return array{text:string, tool_calls:array<int, array{id:string, name:string, arguments:string}>, finish:string, usage:array{prompt:int, completion:int, cost:float|null}, model:string}
     */
    public function result(): array
    {
        ksort($this->_calls);
        $calls = [];

        foreach ($this->_calls as $i => $call) {
            if ($call['name'] === '') {
                continue;
            }
            $call['id'] = $call['id'] !== '' ? $call['id'] : 'call_' . $i;
            $calls[]    = $call;
        }

        return [
            'text'       => $this->_text,
            'tool_calls' => $calls,
            'finish'     => $this->_finish,
            'usage'      => $this->_usage,
            'model'      => $this->_model,
        ];
    }

    private function _line(string $line): void
    {
        if ($line === '' || $line[0] === ':') {
            return; // separator, or a keep-alive comment
        }

        if (strncmp($line, 'data:', 5) !== 0) {
            if (!$this->_sawEvent) {
                $this->_plain .= $line . "\n";
            }

            return;
        }

        $this->_sawEvent = true;
        $data            = trim(substr($line, 5));

        if ($data === '[DONE]') {
            return;
        }

        $json = json_decode($data, true);

        if (!is_array($json)) {
            return;
        }

        if (isset($json['error'])) {
            $this->_error = is_array($json['error']) ? (string) ($json['error']['message'] ?? 'error') : (string) $json['error'];

            return;
        }

        $this->_model = is_string($json['model'] ?? null) ? $json['model'] : $this->_model;
        $this->_usage($json['usage'] ?? null);

        foreach ((array) ($json['choices'] ?? []) as $choice) {
            $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];

            if (is_string($delta['content'] ?? null) && $delta['content'] !== '') {
                $this->_text .= $delta['content'];

                if ($this->_onText) {
                    call_user_func($this->_onText, $delta['content']);
                }
            }

            foreach ((array) ($delta['tool_calls'] ?? []) as $n => $fragment) {
                $i = (int) ($fragment['index'] ?? $n);

                $this->_calls[$i] ??= ['id' => '', 'name' => '', 'arguments' => ''];
                $this->_calls[$i]['id']        .= is_string($fragment['id'] ?? null) && $this->_calls[$i]['id'] === '' ? $fragment['id'] : '';
                $this->_calls[$i]['name']      .= (string) ($fragment['function']['name'] ?? '');
                $this->_calls[$i]['arguments'] .= (string) ($fragment['function']['arguments'] ?? '');
            }

            if (is_string($choice['finish_reason'] ?? null)) {
                $this->_finish = $choice['finish_reason'];
            }
        }
    }

    private function _absorbWhole(array $json): void
    {
        if (isset($json['error'])) {
            $this->_error = is_array($json['error']) ? (string) ($json['error']['message'] ?? 'error') : (string) $json['error'];

            return;
        }

        $this->_model = (string) ($json['model'] ?? '');
        $this->_usage($json['usage'] ?? null);
        $message = $json['choices'][0]['message'] ?? [];

        if (is_string($message['content'] ?? null) && $message['content'] !== '') {
            $this->_text = $message['content'];

            if ($this->_onText) {
                call_user_func($this->_onText, $this->_text);
            }
        }

        foreach ((array) ($message['tool_calls'] ?? []) as $i => $call) {
            $this->_calls[$i] = [
                'id'        => (string) ($call['id'] ?? ''),
                'name'      => (string) ($call['function']['name'] ?? ''),
                'arguments' => (string) ($call['function']['arguments'] ?? ''),
            ];
        }

        $this->_finish = (string) ($json['choices'][0]['finish_reason'] ?? '');
    }

    private function _usage($usage): void
    {
        if (!is_array($usage)) {
            return;
        }

        $this->_usage = [
            'prompt'     => (int) ($usage['prompt_tokens'] ?? 0),
            'completion' => (int) ($usage['completion_tokens'] ?? 0),
            'cost'       => isset($usage['cost']) && is_numeric($usage['cost']) ? (float) $usage['cost'] : null,
        ];
    }
}

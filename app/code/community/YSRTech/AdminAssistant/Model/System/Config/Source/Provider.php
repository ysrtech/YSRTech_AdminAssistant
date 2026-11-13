<?php
class YSRTech_AdminAssistant_Model_System_Config_Source_Provider
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'openrouter', 'label' => 'OpenRouter'],
            ['value' => 'openai', 'label' => 'OpenAI'],
        ];
    }
}

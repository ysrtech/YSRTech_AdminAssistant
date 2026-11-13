<?php
/**
 * What a tool returns passes through here before the model sees it.
 *
 * Two jobs. Anything that looks like a secret is removed by its key, however
 * deep it sits: payment details, password hashes and tokens, keys. And text
 * that a customer wrote (order comments, names, addresses, reviews) is
 * wrapped so the model can tell it is quoted data and not an instruction:
 * {"untrusted_text": "..."}. The system prompt says what that wrapper means.
 */
class YSRTech_AdminAssistant_Model_Sanitizer
{
    /** Keys removed outright, wherever they appear */
    public const SECRET_KEY_PATTERN = '/(password|passwd|pass_hash|rp_token|token|secret|api_?key|private_?key|signature|cc_|card|cvv|cvc|iban|account_?number|routing|additional_information|additional_data|echeck|po_number|encrypt|salt|session|otp|protect_code|hash)/i';

    /** Keys whose value a customer (or anyone outside staff) wrote */
    public const UNTRUSTED_KEY_PATTERN = '/(comment|note|message|firstname|lastname|middlename|prefix|suffix|customer_name|full_name|billing_name|shipping_name|street|city|region$|company|postcode|telephone|fax|review|detail|nickname|gift)/i';

    /** Strings this long or longer are cut, so one field cannot flood the context */
    public const MAX_STRING = 2000;

    public function clean($value, string $key = '')
    {
        if (is_array($value)) {
            $out = [];

            foreach ($value as $k => $v) {
                if (is_string($k) && $this->isSecretKey($k)) {
                    continue;
                }
                $out[$k] = $this->clean($v, is_string($k) ? $k : $key);
            }

            return $out;
        }

        if (is_object($value)) {
            return $this->clean($value instanceof Varien_Object ? $value->getData() : (array) $value, $key);
        }

        if (is_string($value)) {
            if (mb_strlen($value) > self::MAX_STRING) {
                $value = mb_substr($value, 0, self::MAX_STRING) . '… [cut]';
            }

            if ($key !== '' && $this->isUntrustedKey($key) && $value !== '') {
                return ['untrusted_text' => $value];
            }
        }

        return $value;
    }

    public function isSecretKey(string $key): bool
    {
        // A count of things with "card" or "token" in their name is not itself secret
        if (preg_match('/^(qty|count|num|total)_/', $key)) {
            return false;
        }

        return (bool) preg_match(self::SECRET_KEY_PATTERN, $key);
    }

    public function isUntrustedKey(string $key): bool
    {
        return (bool) preg_match(self::UNTRUSTED_KEY_PATTERN, $key);
    }

    /**
     * Quote one piece of outside text explicitly, for a tool that builds its
     * result by hand.
     */
    public static function quote(?string $text): ?array
    {
        $text = (string) $text;

        return $text === '' ? null : ['untrusted_text' => mb_substr($text, 0, self::MAX_STRING)];
    }
}

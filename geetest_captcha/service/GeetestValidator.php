<?php

namespace captcha\geetest_captcha\service;

/**
 * GeeTest CAPTCHA v4 payload validation and server-side verification.
 */
class GeetestValidator
{
    const API_URL = 'https://gcaptcha4.geetest.com/validate';

    public static function decodePayload($captcha)
    {
        if (!is_string($captcha) || $captcha === '' || strlen($captcha) > 16384) {
            return null;
        }

        $decoded = json_decode($captcha, true);
        if (!is_array($decoded)) {
            return null;
        }

        if (isset($decoded['provider']) && $decoded['provider'] !== 'geetest4') {
            return null;
        }

        $required = ['lot_number', 'captcha_output', 'pass_token', 'gen_time'];
        $payload = [];
        foreach ($required as $field) {
            if (!isset($decoded[$field]) || !is_string($decoded[$field])) {
                return null;
            }
            $payload[$field] = $decoded[$field];
        }

        if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/', $payload['lot_number'])
            || !preg_match('/^[A-Za-z0-9_-]{16,512}$/', $payload['pass_token'])
            || !preg_match('/^[0-9]{9,16}$/', $payload['gen_time'])
        ) {
            return null;
        }

        $outputLength = strlen($payload['captcha_output']);
        if ($outputLength < 16 || $outputLength > 8192
            || preg_match('/[\x00-\x1F\x7F]/', $payload['captcha_output'])
        ) {
            return null;
        }

        return $payload;
    }

    public static function signToken($lotNumber, $captchaKey)
    {
        return hash_hmac('sha256', (string) $lotNumber, (string) $captchaKey);
    }

    public static function buildRiskType($mode, $timestamp, $nonce, $captchaKey)
    {
        $modes = ['ai', 'slide', 'match', 'winlinze', 'nine', 'word', 'phrase', 'icon'];
        if (!in_array((string) $mode, $modes, true)
            || !preg_match('/^[0-9]{10}\.[0-9]{6}$/', (string) $timestamp)
            || !preg_match('/^[a-f0-9]{32}$/', (string) $nonce)
            || !preg_match('/^[A-Za-z0-9]{32}$/', (string) $captchaKey)
        ) {
            return '';
        }

        $message = $mode . '|' . $timestamp . '|' . $nonce;
        return $message . '|' . hash_hmac('sha256', $message, $captchaKey);
    }

    public static function payloadDigest(array $payload)
    {
        $canonical = [
            'lot_number'     => (string) ($payload['lot_number'] ?? ''),
            'captcha_output' => (string) ($payload['captcha_output'] ?? ''),
            'pass_token'     => (string) ($payload['pass_token'] ?? ''),
            'gen_time'       => (string) ($payload['gen_time'] ?? ''),
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES));
    }

    public function verify(array $payload, $captchaId, $captchaKey, $timeoutSeconds)
    {
        if (!function_exists('curl_init')) {
            return ['success' => false, 'code' => 'curl_missing'];
        }

        $captchaId = trim((string) $captchaId);
        $captchaKey = trim((string) $captchaKey);
        if (!preg_match('/^[A-Za-z0-9]{32}$/', $captchaId)
            || !preg_match('/^[A-Za-z0-9]{32}$/', $captchaKey)
        ) {
            return ['success' => false, 'code' => 'invalid_config'];
        }

        $postFields = [
            'lot_number'     => $payload['lot_number'],
            'captcha_output' => $payload['captcha_output'],
            'pass_token'     => $payload['pass_token'],
            'gen_time'       => $payload['gen_time'],
            'sign_token'     => self::signToken($payload['lot_number'], $captchaKey),
        ];

        $timeoutSeconds = max(2, min(10, (int) $timeoutSeconds));
        $url = self::API_URL . '?captcha_id=' . rawurlencode($captchaId);
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => http_build_query($postFields, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_CONNECTTIMEOUT  => min(3, $timeoutSeconds),
            CURLOPT_TIMEOUT         => $timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_HTTPHEADER      => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_USERAGENT       => 'ZJMF-V10-GeetestCaptcha/1.3.0',
        ]);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }

        $body = curl_exec($curl);
        $curlError = curl_errno($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($body === false || $curlError !== 0 || $httpCode !== 200
            || !is_string($body) || strlen($body) > 65536
        ) {
            return [
                'success'   => false,
                'code'      => 'provider_unavailable',
                'http_code' => $httpCode,
                'curl_code' => $curlError,
            ];
        }

        $response = json_decode($body, true);
        if (!is_array($response)) {
            return ['success' => false, 'code' => 'invalid_provider_response'];
        }
        if (($response['status'] ?? '') !== 'success'
            || ($response['result'] ?? '') !== 'success'
        ) {
            return [
                'success'       => false,
                'code'          => 'provider_rejected',
                'provider_code' => isset($response['code']) ? (string) $response['code'] : '',
            ];
        }

        return ['success' => true, 'code' => 'success'];
    }
}

<?php

namespace ContractLayer\EContract\Webhooks;

/**
 * X-Econtract-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256(secret, "{t}.{rawBody}")>
 * Docs: https://developers.econtract.online/docs/webhooks
 */
final class SignatureVerifier
{
    public static function verify(string $rawBody, ?string $header, string $secret, int $toleranceSeconds = 300, ?int $now = null): void
    {
        if ($secret === '') {
            throw new WebhookSignatureException('Webhook secret is not configured');
        }
        $parts = [];
        foreach (explode(',', (string) $header) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $parts[trim($key)] = trim($value);
        }
        if (! isset($parts['t'], $parts['v1']) || ! ctype_digit($parts['t']) || $parts['v1'] === '') {
            throw new WebhookSignatureException('Malformed X-Econtract-Signature');
        }
        if (abs(($now ?? time()) - (int) $parts['t']) > $toleranceSeconds) {
            throw new WebhookSignatureException('Signature timestamp outside the tolerance');
        }
        $expected = hash_hmac('sha256', $parts['t'].'.'.$rawBody, $secret);
        if (! hash_equals($expected, strtolower($parts['v1']))) {
            throw new WebhookSignatureException('Signature mismatch');
        }
    }

    /** Signs like eContract does (tests, local simulation). */
    public static function sign(string $rawBody, string $secret, ?int $timestamp = null): string
    {
        $t = $timestamp ?? time();

        return 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$rawBody, $secret);
    }
}

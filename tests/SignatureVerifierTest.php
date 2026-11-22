<?php

namespace ContractLayer\EContract\Tests;

use ContractLayer\EContract\Webhooks\SignatureVerifier;
use ContractLayer\EContract\Webhooks\WebhookSignatureException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as PlainTestCase;

class SignatureVerifierTest extends PlainTestCase
{
    private const BODY = '{"id":"evt_1","event":"contract.completed","data":{"contractId":"c1"}}';

    public function test_accepts_a_body_signed_with_the_secret(): void
    {
        SignatureVerifier::verify(self::BODY, SignatureVerifier::sign(self::BODY, 's3cret'), 's3cret');
        $this->addToAssertionCount(1);
    }

    public static function rejected(): array
    {
        $t = time();

        return [
            'modified body' => [str_replace('c1', 'c2', self::BODY), SignatureVerifier::sign(self::BODY, 's3cret'), 's3cret', 'mismatch'],
            'other secret' => [self::BODY, SignatureVerifier::sign(self::BODY, 'other'), 's3cret', 'mismatch'],
            'replayed (old timestamp)' => [self::BODY, SignatureVerifier::sign(self::BODY, 's3cret', $t - 600), 's3cret', 'tolerance'],
            'malformed header' => [self::BODY, 'garbage', 's3cret', 'Malformed'],
            'missing header' => [self::BODY, null, 's3cret', 'Malformed'],
            'no secret configured' => [self::BODY, SignatureVerifier::sign(self::BODY, 's3cret'), '', 'not configured'],
        ];
    }

    #[DataProvider('rejected')]
    public function test_rejects(string $body, ?string $header, string $secret, string $message): void
    {
        $this->expectException(WebhookSignatureException::class);
        $this->expectExceptionMessage($message);
        SignatureVerifier::verify($body, $header, $secret);
    }
}

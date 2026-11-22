<?php

namespace ContractLayer\EContract\EContract;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/** Thin HTTP client of the eContract API. Docs: https://developers.econtract.online/docs/integration */
class EContractClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.econtract.online',
        private readonly int $timeout = 30,
        private readonly int $retries = 2,
    ) {
    }

    /**
     * POSTs carry an Idempotency-Key, the same on every retry, so a retried request after a timeout
     * never creates a second contract. 429, 5xx and connection errors are retried.
     */
    public function request(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): Response
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('ECONTRACT_API_KEY is not set');
        }
        $headers = $method === 'POST' ? ['Idempotency-Key' => $idempotencyKey ?? (string) Str::uuid()] : [];
        $response = $this->http()
            ->withHeaders($headers)
            ->retry($this->retries + 1, fn (int $attempt) => 300 * 2 ** ($attempt - 1), function ($e) {
                return $e instanceof ConnectionException
                    || ($e instanceof RequestException && ($e->response->status() === 429 || $e->response->serverError()));
            }, throw: false)
            ->send($method, $path, $body === null ? [] : ['json' => $body]);

        if ($response->failed()) {
            throw new EContractException($response);
        }

        return $response;
    }

    public function sendContract(array $payload, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/contracts/send', $payload, $idempotencyKey)->json();
    }

    public function getContract(string $id): array
    {
        return $this->request('GET', '/contracts/'.rawurlencode($id))->json();
    }

    /** Contracts sent with this externalRef, newest first. */
    public function findByExternalRef(string $externalRef): array
    {
        return $this->request('GET', '/contracts?'.http_build_query(['externalRef' => $externalRef, 'limit' => 20]))->json('data', []);
    }

    public function signaturePositions(string $id): array
    {
        return $this->request('GET', '/contracts/'.rawurlencode($id).'/signature-positions')->json();
    }

    public function setSignaturePositions(string $id, array $signers): array
    {
        return $this->request('PUT', '/contracts/'.rawurlencode($id).'/signature-positions', ['signers' => $signers])->json();
    }

    public function submit(string $id, string $delivery = 'email', ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/contracts/'.rawurlencode($id).'/submit', ['delivery' => $delivery], $idempotencyKey)->json();
    }

    public function signingLinks(string $id): array
    {
        return $this->request('POST', '/contracts/'.rawurlencode($id).'/signing-links', [])->json('data', []);
    }

    public function void(string $id, string $reason): array
    {
        return $this->request('POST', '/contracts/'.rawurlencode($id).'/void', ['reason' => $reason])->json();
    }

    /** @return array{bytes: string, sha256: ?string} */
    public function document(string $id, ?string $version = null): array
    {
        return $this->download('/contracts/'.rawurlencode($id).'/document'.($version ? '?version='.$version : ''));
    }

    /** A path from a webhook (contract.completed → data.document.downloadPath) or relative to /api/v1. */
    public function download(string $path): array
    {
        $response = $this->request('GET', preg_replace('#^/api/v1#', '', $path));

        return ['bytes' => $response->body(), 'sha256' => $response->header('X-Document-Sha256') ?: null];
    }

    public function createWebhook(string $url, array $events): array
    {
        return $this->request('POST', '/webhooks', ['url' => $url, 'events' => $events, 'description' => 'Laravel integration'])->json();
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/').'/api/v1')
            ->withToken($this->apiKey)
            ->acceptJson()
            ->timeout($this->timeout);
    }
}

<?php

namespace ContractLayer\EContract\EContract;

use Illuminate\Http\Client\Response;
use RuntimeException;

/** An API error: status, machine code (e.g. CONTRACT_NOT_DRAFT) and the requestId to quote to support. */
class EContractException extends RuntimeException
{
    public readonly int $status;

    public readonly ?string $errorCode;

    public readonly ?string $requestId;

    public function __construct(public readonly Response $response)
    {
        $this->status = $response->status();
        $this->errorCode = $response->json('code') ?? $response->json('error');
        $this->requestId = $response->json('requestId');
        $message = $response->json('message');
        parent::__construct(sprintf('eContract API %d: %s', $this->status, is_array($message) ? implode('; ', $message) : ($message ?? $response->body())));
    }
}

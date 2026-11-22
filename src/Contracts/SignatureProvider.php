<?php

namespace ContractLayer\EContract\Contracts;

use ContractLayer\EContract\Data\SignatureDraft;
use ContractLayer\EContract\Models\SignatureRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * What your application needs from an e-signature provider. Business code (HR, sales, procurement)
 * talks to this interface only; EContractProvider is the eContract implementation.
 */
interface SignatureProvider
{
    /** Uploads the document and creates the request as a draft: nobody is invited yet. */
    public function createDraft(Model $signable, SignatureDraft $draft): SignatureRequest;

    /** @return array<int, array{signerId: string, email: ?string, name: ?string, positions: array}> */
    public function signaturePositions(SignatureRequest $request): array;

    /** @param array<int, array{signerId?: string, email?: string, positions: array}> $signers */
    public function setSignaturePositions(SignatureRequest $request, array $signers): array;

    /** Starts signing. delivery "email": the provider emails signers; "link": links are returned to you. */
    public function submit(SignatureRequest $request, string $delivery = 'email'): SignatureRequest;

    public function cancel(SignatureRequest $request, string $reason): SignatureRequest;

    /** @return array{bytes: string, sha256: ?string} */
    public function downloadSigned(SignatureRequest $request, ?string $downloadPath = null): array;
}

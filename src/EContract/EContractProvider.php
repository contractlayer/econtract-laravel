<?php

namespace ContractLayer\EContract\EContract;

use ContractLayer\EContract\Contracts\SignatureProvider;
use ContractLayer\EContract\Data\SignatureDraft;
use ContractLayer\EContract\Models\SignatureRequest;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class EContractProvider implements SignatureProvider
{
    public function __construct(private readonly EContractClient $client)
    {
    }

    public function createDraft(Model $signable, SignatureDraft $draft): SignatureRequest
    {
        $attempt = SignatureRequest::where('external_ref', $draft->externalRef)->count() + 1;
        $signers = array_values(array_map(fn (array $s, int $i) => array_filter([
            'email' => $s['email'],
            'name' => $s['name'] ?? null,
            'signOrder' => $i + 1,
            'signatureAnchor' => isset($s['anchor']) ? ['text' => $s['anchor']] : null,
        ]), $draft->signers, array_keys($draft->signers)));

        $contract = $this->client->sendContract([
            'title' => $draft->title,
            'submit' => false,
            'externalRef' => $draft->externalRef,
            'requestedBy' => $draft->requestedBy,
            'metadata' => $draft->metadata ?: null,
            'signingOrderType' => $draft->sequential ? 'sequential' : 'parallel',
            'files' => [[
                'filename' => $draft->filename,
                'contentBase64' => base64_encode($draft->content),
                'contentType' => str_ends_with(strtolower($draft->filename), '.docx')
                    ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                    : 'application/pdf',
            ]],
            'signers' => $signers,
        ], "{$draft->externalRef}-send-{$attempt}");

        return SignatureRequest::create([
            'signable_type' => $signable->getMorphClass(),
            'signable_id' => $signable->getKey(),
            'external_ref' => $draft->externalRef,
            'provider_contract_id' => $contract['id'],
            'provider_code' => $contract['code'] ?? null,
            'status' => SignatureRequest::PROCESSING,
        ]);
    }

    public function signaturePositions(SignatureRequest $request): array
    {
        return $this->client->signaturePositions($request->provider_contract_id)['signers'] ?? [];
    }

    public function setSignaturePositions(SignatureRequest $request, array $signers): array
    {
        return $this->client->setSignaturePositions($request->provider_contract_id, $signers)['signers'] ?? [];
    }

    public function submit(SignatureRequest $request, string $delivery = 'email'): SignatureRequest
    {
        if ($request->status !== SignatureRequest::DRAFT) {
            throw new RuntimeException("Signature request {$request->id} is {$request->status}, not a draft");
        }
        $this->client->submit($request->provider_contract_id, $delivery, "{$request->external_ref}-submit-{$request->id}");
        $request->update([
            'status' => SignatureRequest::SIGNING,
            'signing_links' => $delivery === 'link' ? $this->client->signingLinks($request->provider_contract_id) : null,
        ]);

        return $request;
    }

    public function cancel(SignatureRequest $request, string $reason): SignatureRequest
    {
        $this->client->void($request->provider_contract_id, $reason);
        $request->update(['status' => SignatureRequest::VOIDED]);

        return $request;
    }

    public function downloadSigned(SignatureRequest $request, ?string $downloadPath = null): array
    {
        return $downloadPath
            ? $this->client->download($downloadPath)
            : $this->client->document($request->provider_contract_id, 'signed');
    }
}

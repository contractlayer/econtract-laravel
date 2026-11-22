<?php

namespace ContractLayer\EContract\Http\Controllers;

use ContractLayer\EContract\Events\SignatureRequestUpdated;
use ContractLayer\EContract\Jobs\DownloadSignedDocument;
use ContractLayer\EContract\Models\SignatureRequest;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies a verified eContract webhook. Answers fast (eContract retries on timeouts and 5xx):
 * the signed PDF download runs in a queued job.
 */
class EContractWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $event = $request->json()->all();
        $name = (string) ($event['event'] ?? '');
        $data = $event['data'] ?? [];

        if (! $this->claim((string) ($event['id'] ?? ''), $name)) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        $signatureRequest = $this->find($data);
        if (! $signatureRequest) {
            Log::info('eContract webhook for an unknown contract', ['event' => $name, 'contractId' => $data['contractId'] ?? null]);

            return response()->json(['received' => true]);
        }

        match ($name) {
            'contract.processing.completed' => $signatureRequest->update([
                'status' => ($data['status'] ?? null) === 'draft' ? SignatureRequest::DRAFT : SignatureRequest::SIGNING,
            ]),
            'contract.processing.failed' => $signatureRequest->update(['status' => SignatureRequest::FAILED, 'last_error' => $data['processingError'] ?? null]),
            'signer.viewed', 'signer.completed', 'signer.declined' => $this->signer($signatureRequest, $name, $data),
            'contract.voided' => $signatureRequest->status === SignatureRequest::DECLINED ? null : $signatureRequest->update(['status' => SignatureRequest::VOIDED]),
            'contract.expired' => $signatureRequest->update(['status' => SignatureRequest::EXPIRED]),
            'contract.completed' => DownloadSignedDocument::dispatch($signatureRequest->id, $data['document'] ?? null),
            default => null,
        };

        SignatureRequestUpdated::dispatch($signatureRequest->fresh(), $name, $data);

        return response()->json(['received' => true]);
    }

    /** True the first time an event id is seen (the primary key makes it race-safe). */
    private function claim(string $eventId, string $name): bool
    {
        if ($eventId === '') {
            return true;
        }
        try {
            DB::table('econtract_webhook_events')->insert(['event_id' => $eventId, 'event' => $name, 'received_at' => now()]);

            return true;
        } catch (QueryException) {
            return false;
        }
    }

    /** By contract id; an event of an older contract with the same externalRef does not match. */
    private function find(array $data): ?SignatureRequest
    {
        $contractId = $data['contractId'] ?? $data['contract']['id'] ?? null;

        return $contractId ? SignatureRequest::where('provider_contract_id', $contractId)->first() : null;
    }

    private function signer(SignatureRequest $request, string $name, array $data): void
    {
        $key = $data['signer']['email'] ?? $data['signerId'] ?? 'unknown';
        $state = ['signer.viewed' => 'viewed', 'signer.completed' => 'signed', 'signer.declined' => 'declined'][$name];
        $request->update([
            'signers' => array_merge($request->signers ?? [], [$key => $state]),
            ...($name === 'signer.declined' ? ['status' => SignatureRequest::DECLINED] : []),
        ]);
    }
}

<?php

namespace ContractLayer\EContract\Jobs;

use ContractLayer\EContract\Contracts\SignatureProvider;
use ContractLayer\EContract\Events\SignatureRequestUpdated;
use ContractLayer\EContract\Models\SignatureRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Downloads the sealed PDF and stores it only if its SHA-256 equals the hash eContract announced
 * (webhook data.document.sha256 and the X-Document-Sha256 header).
 */
class DownloadSignedDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public array $backoff = [10, 60, 300, 900];

    public function __construct(public readonly int $signatureRequestId, public readonly ?array $document = null)
    {
    }

    public function handle(SignatureProvider $provider): void
    {
        $request = SignatureRequest::findOrFail($this->signatureRequestId);
        if ($request->status === SignatureRequest::SIGNED) {
            return;
        }
        ['bytes' => $bytes, 'sha256' => $headerSha] = $provider->downloadSigned($request, $this->document['downloadPath'] ?? null);
        $actual = hash('sha256', $bytes);
        $expected = $this->document['sha256'] ?? $headerSha;
        if (! $expected || ! hash_equals($expected, $actual) || ($headerSha && ! hash_equals($headerSha, $actual))) {
            $request->update(['last_error' => "SHA-256 mismatch: got {$actual}, expected {$expected}"]);
            throw new RuntimeException("Signed PDF of signature request {$request->id} does not match its SHA-256");
        }

        $path = trim(config('econtract.directory'), '/')."/{$request->external_ref}-{$request->provider_code}.pdf";
        Storage::disk(config('econtract.disk'))->put($path, $bytes);
        $request->update([
            'status' => SignatureRequest::SIGNED,
            'signed_path' => $path,
            'signed_sha256' => $actual,
            'completed_at' => now(),
            'last_error' => null,
        ]);
        SignatureRequestUpdated::dispatch($request, 'document.stored');
    }
}

<?php

namespace ContractLayer\EContract\Console;

use ContractLayer\EContract\EContract\EContractClient;
use ContractLayer\EContract\Jobs\DownloadSignedDocument;
use ContractLayer\EContract\Models\SignatureRequest;
use Illuminate\Console\Command;

/** Catches up on missed webhooks. Schedule it hourly: Schedule::command('econtract:reconcile')->hourly(); */
class ReconcileSignatureRequests extends Command
{
    protected $signature = 'econtract:reconcile';

    protected $description = 'Sync open signature requests with eContract (missed webhooks)';

    public function handle(EContractClient $client): int
    {
        $open = SignatureRequest::whereIn('status', [SignatureRequest::PROCESSING, SignatureRequest::DRAFT, SignatureRequest::SIGNING])->get();
        foreach ($open as $request) {
            $contract = collect($client->findByExternalRef($request->external_ref))->firstWhere('id', $request->provider_contract_id);
            if (! $contract) {
                continue;
            }
            match (true) {
                $contract['status'] === 'completed' => DownloadSignedDocument::dispatch($request->id),
                $contract['status'] === 'voided' => $request->update(['status' => SignatureRequest::VOIDED]),
                $contract['status'] === 'expired' => $request->update(['status' => SignatureRequest::EXPIRED]),
                ($contract['processingStatus'] ?? null) === 'failed' => $request->update(['status' => SignatureRequest::FAILED]),
                $contract['status'] === 'draft' && ($contract['processingStatus'] ?? null) === 'ready' => $request->update(['status' => SignatureRequest::DRAFT]),
                default => null,
            };
        }
        $this->info("Checked {$open->count()} open signature request(s)");

        return self::SUCCESS;
    }
}

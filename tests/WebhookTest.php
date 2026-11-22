<?php

namespace ContractLayer\EContract\Tests;

use ContractLayer\EContract\Events\SignatureRequestUpdated;
use ContractLayer\EContract\Jobs\DownloadSignedDocument;
use ContractLayer\EContract\Models\SignatureRequest;
use ContractLayer\EContract\Webhooks\SignatureVerifier;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

class WebhookTest extends TestCase
{
    private function signatureRequest(string $status = SignatureRequest::PROCESSING): SignatureRequest
    {
        $employee = Employee::create(['name' => 'An', 'email' => 'an@example.com']);

        return SignatureRequest::create(['signable_type' => $employee->getMorphClass(), 'signable_id' => $employee->id, 'external_ref' => 'HR-1', 'provider_contract_id' => 'c1', 'provider_code' => 'CTR-1', 'status' => $status]);
    }

    private function deliver(string $id, string $event, array $data, ?string $secret = 'whsec_test')
    {
        $body = json_encode(['id' => $id, 'event' => $event, 'apiVersion' => '2026-11', 'data' => $data]);

        return $this->call('POST', '/webhooks/econtract', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ECONTRACT_SIGNATURE' => SignatureVerifier::sign($body, $secret),
        ], $body);
    }

    public function test_rejects_a_bad_signature(): void
    {
        $request = $this->signatureRequest();
        $this->deliver('evt_1', 'contract.processing.completed', ['contractId' => 'c1', 'status' => 'draft'], 'wrong')->assertStatus(400);
        $this->assertSame(SignatureRequest::PROCESSING, $request->fresh()->status);
    }

    public function test_processing_completed_makes_a_draft_once_and_ignores_the_duplicate(): void
    {
        Event::fake([SignatureRequestUpdated::class]);
        $request = $this->signatureRequest();

        $this->deliver('evt_1', 'contract.processing.completed', ['contractId' => 'c1', 'status' => 'draft'])->assertOk()->assertJson(['received' => true]);
        $this->assertSame(SignatureRequest::DRAFT, $request->fresh()->status);

        $request->update(['status' => SignatureRequest::SIGNING]);
        $this->deliver('evt_1', 'contract.processing.completed', ['contractId' => 'c1', 'status' => 'draft'])->assertJson(['duplicate' => true]);
        $this->assertSame(SignatureRequest::SIGNING, $request->fresh()->status);
        Event::assertDispatchedTimes(SignatureRequestUpdated::class, 1);
    }

    public function test_signer_declined_records_the_signer_and_a_later_void_keeps_declined(): void
    {
        $request = $this->signatureRequest(SignatureRequest::SIGNING);
        $this->deliver('evt_2', 'signer.declined', ['contractId' => 'c1', 'signer' => ['email' => 'an@example.com']]);
        $this->deliver('evt_3', 'contract.voided', ['contractId' => 'c1']);

        $fresh = $request->fresh();
        $this->assertSame(SignatureRequest::DECLINED, $fresh->status);
        $this->assertSame(['an@example.com' => 'declined'], $fresh->signers);
    }

    public function test_an_event_of_another_contract_is_ignored(): void
    {
        $request = $this->signatureRequest();
        $this->deliver('evt_4', 'contract.voided', ['contractId' => 'c-old', 'contract' => ['externalRef' => 'HR-1']])->assertOk();
        $this->assertSame(SignatureRequest::PROCESSING, $request->fresh()->status);
    }

    public function test_completed_queues_the_download(): void
    {
        Queue::fake();
        $request = $this->signatureRequest(SignatureRequest::SIGNING);
        $document = ['sha256' => str_repeat('a', 64), 'downloadPath' => '/api/v1/contracts/c1/document?version=signed'];

        $this->deliver('evt_5', 'contract.completed', ['contractId' => 'c1', 'document' => $document])->assertOk();

        Queue::assertPushed(DownloadSignedDocument::class, fn ($job) => $job->signatureRequestId === $request->id && $job->document === $document);
    }

    public function test_download_job_stores_the_pdf_when_the_hash_matches(): void
    {
        Storage::fake('local');
        $bytes = '%PDF-1.4 sealed';
        Http::fake(['api.example.test/api/v1/contracts/c1/document?version=signed' => Http::response($bytes, 200, ['X-Document-Sha256' => hash('sha256', $bytes)])]);
        $request = $this->signatureRequest(SignatureRequest::SIGNING);

        dispatch_sync(new DownloadSignedDocument($request->id, ['sha256' => hash('sha256', $bytes), 'downloadPath' => '/api/v1/contracts/c1/document?version=signed']));

        $fresh = $request->fresh();
        $this->assertSame(SignatureRequest::SIGNED, $fresh->status);
        $this->assertSame(hash('sha256', $bytes), $fresh->signed_sha256);
        Storage::disk('local')->assertExists($fresh->signed_path);
        $this->assertSame($bytes, Storage::disk('local')->get($fresh->signed_path));
    }

    public function test_download_job_refuses_a_pdf_whose_hash_does_not_match(): void
    {
        Storage::fake('local');
        Http::fake(['*' => Http::response('%PDF-1.4 tampered', 200, ['X-Document-Sha256' => str_repeat('b', 64)])]);
        $request = $this->signatureRequest(SignatureRequest::SIGNING);

        try {
            dispatch_sync(new DownloadSignedDocument($request->id, ['sha256' => str_repeat('b', 64), 'downloadPath' => '/api/v1/contracts/c1/document?version=signed']));
            $this->fail('expected a SHA-256 mismatch');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('SHA-256', $e->getMessage());
        }

        $fresh = $request->fresh();
        $this->assertSame(SignatureRequest::SIGNING, $fresh->status);
        $this->assertStringContainsString('mismatch', $fresh->last_error);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_reconcile_command_queues_the_download_of_a_completed_contract(): void
    {
        Queue::fake();
        Http::fake(['api.example.test/api/v1/contracts?*' => Http::response(['data' => [['id' => 'c1', 'status' => 'completed', 'processingStatus' => 'ready']], 'meta' => []])]);
        $request = $this->signatureRequest(SignatureRequest::SIGNING);

        $this->artisan('econtract:reconcile')->assertSuccessful();

        Queue::assertPushed(DownloadSignedDocument::class, fn ($job) => $job->signatureRequestId === $request->id);
    }
}

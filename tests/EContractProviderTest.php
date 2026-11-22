<?php

namespace ContractLayer\EContract\Tests;

use ContractLayer\EContract\Contracts\SignatureProvider;
use ContractLayer\EContract\Data\SignatureDraft;
use ContractLayer\EContract\EContract\EContractException;
use ContractLayer\EContract\Models\SignatureRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class EContractProviderTest extends TestCase
{
    private function draft(Employee $employee): SignatureDraft
    {
        return new SignatureDraft(
            title: 'Employment contract',
            filename: 'HR-1.pdf',
            content: '%PDF-1.4 test',
            signers: [
                ['email' => 'jane@example.com', 'name' => 'Jane', 'anchor' => 'THE EMPLOYER'],
                ['email' => $employee->email, 'name' => $employee->name, 'anchor' => 'THE EMPLOYEE'],
            ],
            externalRef: 'HR-1',
            requestedBy: ['name' => 'HR', 'email' => 'hr@example.com', 'externalId' => 'u1'],
        );
    }

    public function test_create_draft_sends_a_draft_with_reference_anchors_and_idempotency_key(): void
    {
        Http::fake(['api.example.test/api/v1/contracts/send' => Http::response(['id' => 'c1', 'code' => 'CTR-1', 'status' => 'draft'])]);
        $employee = Employee::create(['name' => 'An', 'email' => 'an@example.com']);

        $request = app(SignatureProvider::class)->createDraft($employee, $this->draft($employee));

        $this->assertSame('c1', $request->provider_contract_id);
        $this->assertSame(SignatureRequest::PROCESSING, $request->status);
        $this->assertTrue($request->signable->is($employee));
        Http::assertSent(function (Request $r) {
            $body = $r->data();

            return $r->hasHeader('Authorization', 'Bearer cl_test_key')
                && $r->header('Idempotency-Key')[0] === 'HR-1-send-1'
                && $body['submit'] === false
                && $body['externalRef'] === 'HR-1'
                && $body['requestedBy']['email'] === 'hr@example.com'
                && base64_decode($body['files'][0]['contentBase64']) === '%PDF-1.4 test'
                && $body['signers'][1] === ['email' => 'an@example.com', 'name' => 'An', 'signOrder' => 2, 'signatureAnchor' => ['text' => 'THE EMPLOYEE']];
        });
    }

    public function test_retries_a_server_error_with_the_same_idempotency_key(): void
    {
        Http::fake(['api.example.test/*' => Http::sequence()->push('busy', 503)->push(['id' => 'c1'])]);
        $employee = Employee::create(['name' => 'An', 'email' => 'an@example.com']);

        app(SignatureProvider::class)->createDraft($employee, $this->draft($employee));

        $keys = collect(Http::recorded())->map(fn ($pair) => $pair[0]->header('Idempotency-Key')[0])->unique();
        $this->assertCount(2, Http::recorded());
        $this->assertSame(['HR-1-send-1'], $keys->values()->all());
    }

    public function test_submit_requires_a_draft_and_returns_links_for_link_delivery(): void
    {
        Http::fake([
            'api.example.test/api/v1/contracts/c1/submit' => Http::response(['id' => 'c1']),
            'api.example.test/api/v1/contracts/c1/signing-links' => Http::response(['data' => [['email' => 'an@example.com', 'signingUrl' => 'https://econtract.online/sign/t']]]),
        ]);
        $employee = Employee::create(['name' => 'An', 'email' => 'an@example.com']);
        $request = SignatureRequest::create(['signable_type' => $employee->getMorphClass(), 'signable_id' => $employee->id, 'external_ref' => 'HR-1', 'provider_contract_id' => 'c1', 'status' => SignatureRequest::PROCESSING]);
        $provider = app(SignatureProvider::class);

        try {
            $provider->submit($request, 'link');
            $this->fail('submit of a non-draft must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not a draft', $e->getMessage());
        }
        Http::assertNothingSent();

        $request->update(['status' => SignatureRequest::DRAFT]);
        $provider->submit($request, 'link');

        $this->assertSame(SignatureRequest::SIGNING, $request->status);
        $this->assertSame('https://econtract.online/sign/t', $request->signing_links[0]['signingUrl']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/submit') && $r->data() === ['delivery' => 'link']);
    }

    public function test_api_errors_carry_status_code_and_request_id(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Contract is not a draft', 'error' => 'CONTRACT_NOT_DRAFT', 'statusCode' => 409, 'requestId' => 'req_1'], 409)]);
        $employee = Employee::create(['name' => 'An', 'email' => 'an@example.com']);
        $request = SignatureRequest::create(['signable_type' => $employee->getMorphClass(), 'signable_id' => $employee->id, 'external_ref' => 'HR-1', 'provider_contract_id' => 'c1', 'status' => SignatureRequest::DRAFT]);

        try {
            app(SignatureProvider::class)->setSignaturePositions($request, []);
            $this->fail('expected an exception');
        } catch (EContractException $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame('CONTRACT_NOT_DRAFT', $e->errorCode);
            $this->assertSame('req_1', $e->requestId);
        }
        Http::assertSentCount(1);
    }
}

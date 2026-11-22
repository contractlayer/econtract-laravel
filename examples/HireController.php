<?php

// Example: how an HR app's controller uses the package. Copy into app/Http/Controllers and adapt.

namespace App\Http\Controllers;

use App\Models\Employee;
use ContractLayer\EContract\Contracts\SignatureProvider;
use ContractLayer\EContract\Data\SignatureDraft;
use ContractLayer\EContract\Events\SignatureRequestUpdated;
use ContractLayer\EContract\Models\SignatureRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

class HireController
{
    public function __construct(private readonly SignatureProvider $signatures)
    {
    }

    /** 1. Send the contract as a draft. eContract processes it and calls the webhook (status "draft"). */
    public function send(Request $request, Employee $employee)
    {
        $pdf = view('contracts.employment', ['employee' => $employee])->render(); // or your PDF generator
        $signatureRequest = $this->signatures->createDraft($employee, new SignatureDraft(
            title: "Employment contract — {$employee->name}",
            filename: "HR-{$employee->id}.pdf",
            content: app('dompdf.wrapper')->loadHTML($pdf)->output(),
            signers: [
                ['email' => config('hr.signatory_email'), 'name' => config('hr.signatory_name'), 'anchor' => 'THE EMPLOYER'],
                ['email' => $employee->email, 'name' => $employee->name, 'anchor' => 'THE EMPLOYEE'],
            ],
            externalRef: "HR-{$employee->id}",
            requestedBy: ['name' => $request->user()->name, 'email' => $request->user()->email, 'externalId' => (string) $request->user()->id],
            metadata: ['employeeId' => $employee->id],
        ));

        return back()->with('status', "Sent as {$signatureRequest->provider_code}, waiting for the draft");
    }

    /** 2. (optional) Boxes chosen in your own UI (pdf.js): pages from 1, PDF points, y from the top. */
    public function positions(Request $request, SignatureRequest $signatureRequest)
    {
        return $this->signatures->setSignaturePositions($signatureRequest, $request->input('signers'));
    }

    /** 3. Approved internally: start signing. */
    public function approve(Request $request, SignatureRequest $signatureRequest)
    {
        $this->signatures->submit($signatureRequest, $request->boolean('links') ? 'link' : 'email');

        return back()->with('links', $signatureRequest->signing_links);
    }

    /** 4. The signed PDF, stored by the DownloadSignedDocument job after its SHA-256 was checked. */
    public function signed(SignatureRequest $signatureRequest)
    {
        abort_unless($signatureRequest->status === SignatureRequest::SIGNED, 404);

        return Storage::disk(config('econtract.disk'))->download($signatureRequest->signed_path);
    }
}

// In a service provider: react to signature changes in your domain
// Event::listen(function (SignatureRequestUpdated $e) {
//     if ($e->signatureRequest->status === SignatureRequest::SIGNED) {
//         $e->signatureRequest->signable->update(['hired_at' => now()]);
//     }
// });

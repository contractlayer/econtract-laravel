<?php

namespace ContractLayer\EContract\Http\Middleware;

use Closure;
use ContractLayer\EContract\Webhooks\SignatureVerifier;
use ContractLayer\EContract\Webhooks\WebhookSignatureException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Rejects a webhook unless X-Econtract-Signature matches the RAW body. */
class VerifyEContractSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            SignatureVerifier::verify(
                $request->getContent(),
                $request->header('X-Econtract-Signature'),
                (string) config('econtract.webhook_secret'),
                (int) config('econtract.webhook_tolerance', 300),
            );
        } catch (WebhookSignatureException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }

        return $next($request);
    }
}

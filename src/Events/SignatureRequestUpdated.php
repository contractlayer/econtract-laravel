<?php

namespace ContractLayer\EContract\Events;

use ContractLayer\EContract\Models\SignatureRequest;
use Illuminate\Foundation\Events\Dispatchable;

/** Listen to this in your app (e.g. mark the employee as hired when the status becomes "signed"). */
class SignatureRequestUpdated
{
    use Dispatchable;

    public function __construct(
        public readonly SignatureRequest $signatureRequest,
        public readonly string $event,
        public readonly array $data = [],
    ) {
    }
}

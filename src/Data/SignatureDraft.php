<?php

namespace ContractLayer\EContract\Data;

/**
 * A document to sign. Signers: [['email' => …, 'name' => …, 'anchor' => 'THE EMPLOYEE'], …] in signing
 * order; 'anchor' is text in the document, the signature box goes below it.
 */
final class SignatureDraft
{
    public function __construct(
        public readonly string $title,
        public readonly string $filename,
        public readonly string $content,
        public readonly array $signers,
        public readonly string $externalRef,
        public readonly ?array $requestedBy = null,
        public readonly array $metadata = [],
        public readonly bool $sequential = true,
    ) {
    }
}

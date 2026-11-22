<?php

namespace ContractLayer\EContract\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SignatureRequest extends Model
{
    public const PROCESSING = 'processing';
    public const DRAFT = 'draft';
    public const FAILED = 'failed';
    public const SIGNING = 'signing';
    public const SIGNED = 'signed';
    public const DECLINED = 'declined';
    public const VOIDED = 'voided';
    public const EXPIRED = 'expired';

    protected $guarded = ['id'];

    protected $casts = [
        'signers' => 'array',
        'signing_links' => 'array',
        'completed_at' => 'datetime',
    ];

    public function signable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::PROCESSING, self::DRAFT, self::SIGNING], true);
    }
}

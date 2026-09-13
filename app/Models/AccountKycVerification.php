<?php

namespace App\Models;

use App\Enums\KycVerificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class AccountKycVerification extends Model
{
    protected $fillable = [
        'initiated_by_user_id',
        'owner_seller_id',
        'package_id',
        'account_id',
        'status',
        'first_name',
        'last_name',
        'national_code',
        'national_code_enc',
        'national_code_hash',
        'birth_date',
        'card_number',
        'card_number_enc',
        'card_number_last4',
        'document_disk',
        'document_path',
        'document_path_enc',
        'document_original_name',
        'document_mime',
        'document_size',
        'has_document',
        'verify_attempts',
        'max_verify_attempts',
        'verified_at',
        'locked_at',
        'reset_requested_at',
        'reset_by_admin_id',
        'reset_at',
        'last_api_result',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'status' => KycVerificationStatus::class,
            'has_document' => 'boolean',
            'verify_attempts' => 'integer',
            'max_verify_attempts' => 'integer',
            'document_size' => 'integer',
            'verified_at' => 'datetime',
            'locked_at' => 'datetime',
            'reset_requested_at' => 'datetime',
            'reset_at' => 'datetime',
            'last_api_result' => 'array',
        ];
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    public function ownerSeller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_seller_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function resetByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reset_by_admin_id');
    }

    public function setNationalCodeAttribute(string $value): void
    {
        $normalized = \App\Services\Kyc\IranIdentityValidator::normalizeNationalCode($value);
        $this->attributes['national_code_enc'] = Crypt::encryptString($normalized);
        $this->attributes['national_code_hash'] = hash_hmac('sha256', $normalized, (string) config('app.key'));
    }

    public function getNationalCodeAttribute(): ?string
    {
        $enc = $this->attributes['national_code_enc'] ?? null;
        if (! $enc) {
            return null;
        }

        try {
            return Crypt::decryptString($enc);
        } catch (\Throwable) {
            return null;
        }
    }

    public function setCardNumberAttribute(string $value): void
    {
        $normalized = \App\Services\Kyc\IranIdentityValidator::normalizeCardNumber($value);
        $this->attributes['card_number_enc'] = Crypt::encryptString($normalized);
        $this->attributes['card_number_last4'] = substr($normalized, -4);
    }

    public function getCardNumberAttribute(): ?string
    {
        $enc = $this->attributes['card_number_enc'] ?? null;
        if (! $enc) {
            return null;
        }

        try {
            return Crypt::decryptString($enc);
        } catch (\Throwable) {
            return null;
        }
    }

    public function setDocumentPathAttribute(?string $path): void
    {
        $this->attributes['document_path_enc'] = $path
            ? Crypt::encryptString($path)
            : null;
        $this->attributes['has_document'] = $path !== null && $path !== '';
    }

    public function getDocumentPathAttribute(): ?string
    {
        $enc = $this->attributes['document_path_enc'] ?? null;
        if (! $enc) {
            return null;
        }

        try {
            return Crypt::decryptString($enc);
        } catch (\Throwable) {
            return null;
        }
    }

    public function remainingAttempts(): int
    {
        return max(0, (int) $this->max_verify_attempts - (int) $this->verify_attempts);
    }

    public function isVerified(): bool
    {
        return $this->status === KycVerificationStatus::Verified;
    }

    public function isLocked(): bool
    {
        return in_array($this->status, [
            KycVerificationStatus::Locked,
            KycVerificationStatus::ResetRequested,
        ], true);
    }

    public function canVerify(): bool
    {
        return $this->status === KycVerificationStatus::Draft
            && $this->remainingAttempts() > 0
            && $this->account_id === null
            && (bool) $this->has_document;
    }

    public function maskedNationalCode(): string
    {
        $code = $this->national_code ?? '';
        if (strlen($code) < 5) {
            return '**********';
        }

        return substr($code, 0, 3).'****'.substr($code, -3);
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }
}

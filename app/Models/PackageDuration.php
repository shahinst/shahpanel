<?php

namespace App\Models;

use App\Enums\PackageDurationTier;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class PackageDuration extends Model
{
    protected $fillable = [
        'package_id',
        'tier',
        'price',
        'is_enabled',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * Tolerate NULL/unknown tier values without crashing the client-pricing page.
     */
    protected function tier(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): PackageDurationTier {
                if ($value instanceof PackageDurationTier) {
                    return $value;
                }

                return PackageDurationTier::tryFrom((string) $value) ?? PackageDurationTier::OneMonth;
            },
            set: function (mixed $value): string {
                if ($value instanceof PackageDurationTier) {
                    return $value->value;
                }

                return (PackageDurationTier::tryFrom((string) $value) ?? PackageDurationTier::OneMonth)->value;
            },
        );
    }

    public function expiryFromNow(): ?Carbon
    {
        return $this->expiryFrom(now());
    }

    /**
     * Expiry measured from an explicit base instant. Renewals pass the account's
     * current expiry (when it is still in the future) so that unused days are
     * carried over instead of being lost when renewing early.
     */
    public function expiryFrom(?Carbon $base = null): ?Carbon
    {
        if ($this->tier->hasNoTimeLimit()) {
            return null;
        }

        return ($base ?? now())->copy()->addHours($this->tier->durationHours());
    }

    public function displayLabel(): string
    {
        return $this->tier->label();
    }
}

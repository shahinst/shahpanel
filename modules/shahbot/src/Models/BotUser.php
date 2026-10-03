<?php

namespace Modules\ShahBot\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BotUser extends Model
{
    protected $table = 'shahbot_users';

    /** Set by BotUserService::register() for a user seen for the first time. */
    public bool $wasJustCreated = false;

    protected $fillable = [
        'bot_id',
        'telegram_id',
        'username',
        'first_name',
        'last_name',
        'phone',
        'language',
        'client_user_id',
        'reseller_user_id',
        'referrer_id',
        'step',
        'step_data',
        'is_blocked',
        'bot_blocked_by_user',
        'rules_accepted_at',
        'test_used_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'bot_id' => 'integer',
            'telegram_id' => 'integer',
            'step_data' => 'array',
            'is_blocked' => 'boolean',
            'bot_blocked_by_user' => 'boolean',
            'rules_accepted_at' => 'datetime',
            'test_used_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reseller_user_id');
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(BotInstance::class, 'bot_id');
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referrer_id');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(self::class, 'referrer_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(BotOrder::class, 'bot_user_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BotPayment::class, 'bot_user_id');
    }

    public function displayName(): string
    {
        $name = trim(($this->first_name ?? '').' '.($this->last_name ?? ''));

        if ($name !== '') {
            return $name;
        }

        return $this->username ? '@'.$this->username : (string) $this->telegram_id;
    }

    public function setStep(?string $step, array $data = []): void
    {
        $this->forceFill(['step' => $step, 'step_data' => $step === null ? null : $data])->save();
    }

    public function stepValue(string $key, mixed $default = null): mixed
    {
        return ($this->step_data ?? [])[$key] ?? $default;
    }
}

<?php

namespace Modules\ShahBot\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * The sales bot of an agent or seller. It shares the store, prices, gateways
 * and rules of the main bot, but has its own token, sales owner (the agent),
 * admins, card number and texts, and its own users.
 */
class BotInstance extends Model
{
    /** Settings an agent may set for their own bot. */
    public const OWN_SETTINGS = [
        'admin_chat_ids', 'card_number', 'card_holder', 'card_bank', 'card_note',
        'welcome_text', 'support_text', 'channels', 'rules_text',
    ];

    protected $table = 'shahbot_bots';

    protected $fillable = ['owner_user_id', 'token_enc', 'username', 'webhook_secret', 'settings', 'is_active'];

    protected $hidden = ['token_enc'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'is_active' => 'boolean'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function token(): string
    {
        try {
            return $this->token_enc ? Crypt::decryptString($this->token_enc) : '';
        } catch (Throwable) {
            return '';
        }
    }

    public function setToken(string $token): void
    {
        $this->token_enc = $token === '' ? null : Crypt::encryptString($token);
    }

    /**
     * Values this bot puts over the main bot's settings.
     *
     * @return array<string, string>
     */
    public function overrides(): array
    {
        $own = array_intersect_key((array) ($this->settings ?? []), array_flip(self::OWN_SETTINGS));
        $own = array_filter(array_map(fn ($v) => (string) $v, $own), fn (string $v) => $v !== '');

        return array_merge($own, [
            'bot_token' => $this->token(),
            'bot_username' => (string) $this->username,
            'webhook_secret' => (string) $this->webhook_secret,
            'owner_user_id' => (string) $this->owner_user_id,
            // An agent's admins are their own; the panel admins are never
            // pulled into an agent's bot.
            'admin_chat_ids' => (string) (($this->settings ?? [])['admin_chat_ids'] ?? ''),
        ]);
    }
}

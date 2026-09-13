<?php

namespace App\Models;

use App\Traits\BelongsToHierarchy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Storefront extends Model
{
    use BelongsToHierarchy;

    public $timestamps = false;

    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'user_id',
        'brand_name',
        'tagline',
        'logo_path',
        'primary_color',
        'telegram_contact',
        'instagram_contact',
        'whatsapp_contact',
        'phone_contact',
        'email_contact',
        'website_url',
        'description',
        'support_note',
        'custom_subdomain',
        'slug',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function publicUrl(): string
    {
        return route('storefront.public', $this->slug);
    }

    public function accentColor(): string
    {
        $color = trim((string) $this->primary_color);

        return $color !== '' ? $color : '#6366f1';
    }

    public function hasContactChannels(): bool
    {
        return $this->telegram_contact
            || $this->instagram_contact
            || $this->whatsapp_contact
            || $this->phone_contact
            || $this->email_contact
            || $this->website_url;
    }

    public function telegramUrl(): ?string
    {
        return $this->socialUrl($this->telegram_contact, [
            't.me',
            'telegram.me',
            'telegram.org',
        ], fn (string $handle): string => 'https://t.me/'.ltrim($handle, '@'));
    }

    public function instagramUrl(): ?string
    {
        return $this->socialUrl($this->instagram_contact, [
            'instagram.com',
            'instagr.am',
        ], fn (string $handle): string => 'https://instagram.com/'.ltrim($handle, '@'));
    }

    public function whatsappUrl(): ?string
    {
        if (! $this->whatsapp_contact) {
            return null;
        }

        $raw = trim($this->whatsapp_contact);

        if (Str::startsWith(Str::lower($raw), ['http://', 'https://', 'wa.me/'])) {
            return Str::startsWith(Str::lower($raw), 'http') ? $raw : 'https://'.$raw;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        if (Str::startsWith($digits, '0')) {
            $digits = '98'.substr($digits, 1);
        }

        return 'https://wa.me/'.$digits;
    }

    public function phoneUrl(): ?string
    {
        if (! $this->phone_contact) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $this->phone_contact) ?? '';

        return $digits !== '' ? 'tel:+'.$digits : null;
    }

    /**
     * @param  list<string>  $hosts
     */
    protected function socialUrl(?string $value, array $hosts, callable $fromHandle): ?string
    {
        if (! $value) {
            return null;
        }

        $raw = trim($value);

        if (Str::startsWith(Str::lower($raw), ['http://', 'https://'])) {
            return $raw;
        }

        foreach ($hosts as $host) {
            if (Str::contains(Str::lower($raw), $host)) {
                return Str::startsWith(Str::lower($raw), 'http') ? $raw : 'https://'.$raw;
            }
        }

        return $fromHandle($raw);
    }
}

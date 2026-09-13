<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

class StorefrontSchema
{
    /** @var array<string, bool>|null */
    protected static ?array $columnCache = null;

    public static function hasColumn(string $column): bool
    {
        if (! Schema::hasTable('storefronts')) {
            return false;
        }

        if (self::$columnCache === null) {
            self::$columnCache = [];
        }

        if (! array_key_exists($column, self::$columnCache)) {
            self::$columnCache[$column] = Schema::hasColumn('storefronts', $column);
        }

        return self::$columnCache[$column];
    }

    public static function isEnhanced(): bool
    {
        return self::hasColumn('tagline');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function filterAttributes(array $attributes): array
    {
        $filtered = [];

        foreach ($attributes as $key => $value) {
            if (self::hasColumn($key)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    /**
     * @return list<string>
     */
    public static function enhancedFieldNames(): array
    {
        return [
            'tagline',
            'instagram_contact',
            'whatsapp_contact',
            'email_contact',
            'website_url',
            'support_note',
        ];
    }
}

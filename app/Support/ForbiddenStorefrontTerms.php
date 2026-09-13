<?php

namespace App\Support;

final class ForbiddenStorefrontTerms
{
    /**
     * @return list<string>
     */
    public static function messages(): array
    {
        return [
            __('storefront.forbidden_term_vpn'),
            __('storefront.forbidden_term_vpn_fa'),
        ];
    }

    public static function containsForbidden(string $value): bool
    {
        return self::detectedTerm($value) !== null;
    }

    public static function detectedTerm(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/vpn/i', $value) === 1) {
            return 'vpn';
        }

        $collapsed = self::collapseForMatch($value);

        if ($collapsed !== '' && (str_contains($collapsed, 'ویپیان') || str_contains($collapsed, 'ویپین'))) {
            return 'وی پی ان';
        }

        if (preg_match('/و\s*ی\s*پ\s*ی\s*ان/u', $value) === 1) {
            return 'وی پی ان';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    public static function validatePayload(array $payload): array
    {
        $errors = [];

        foreach ($payload as $field => $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $term = self::detectedTerm($value);

            if ($term !== null) {
                $errors[$field] = __('storefront.forbidden_term_field', ['term' => $term]);
            }
        }

        return $errors;
    }

    protected static function collapseForMatch(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = str_replace(['ي', 'ك'], ['ی', 'ک'], $value);

        return (string) preg_replace('/[\s\x{00a0}\x{200c}\x{200d}\-_\.،,؛;:!?@#]+/u', '', $value);
    }
}

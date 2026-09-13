<?php

namespace App\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * VPN account / service identity: ASCII letters, digits, underscore, hyphen only.
 */
final class AccountNameValidator
{
    /** @var string PCRE (no delimiters) */
    public const PATTERN = '^[a-zA-Z0-9_-]+$';

    public const REMOTE_MAX = 255;

    public const SANAEI_LABEL_MAX = 32;

    public const MIN = 1;

    /**
     * @return list<string|\Illuminate\Contracts\Validation\ValidationRule>
     */
    public static function rules(int $max = self::REMOTE_MAX, bool $required = false): array
    {
        return array_filter([
            $required ? 'required' : 'nullable',
            'string',
            'min:'.self::MIN,
            'max:'.$max,
            'regex:/'.self::PATTERN.'/',
        ]);
    }

    public static function isValid(string $name): bool
    {
        return (bool) preg_match('/'.self::PATTERN.'/', trim($name));
    }

    public static function assertValid(string $name, int $max = self::REMOTE_MAX): string
    {
        $name = trim($name);

        if ($name === '' || ! self::isValid($name)) {
            throw new InvalidArgumentException(__('accounts.account_name_latin_only'));
        }

        if (Str::length($name) > $max) {
            throw new InvalidArgumentException(__('accounts.account_name_too_long', ['max' => $max]));
        }

        return $name;
    }

    public static function normalizeSanaeiLabel(string $name): string
    {
        $name = self::assertValid($name, self::SANAEI_LABEL_MAX);

        return Str::lower($name);
    }
}

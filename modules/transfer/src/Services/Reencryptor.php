<?php

namespace Modules\Transfer\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves every encrypted value of a restored database from the old panel's
 * APP_KEY to this panel's.
 *
 * The values are found by shape rather than by a list of columns: every
 * Laravel payload is base64 JSON that starts with {"iv": (eyJpdiI6), and
 * modules keep them inside JSON settings too. A list would silently miss the
 * next module's column. Base64 of that ASCII JSON never holds a slash, so
 * json_encode leaves the payload untouched.
 */
class Reencryptor
{
    protected const PAYLOAD = '~eyJpdiI6[A-Za-z0-9+=]+~';

    protected const TEXT_TYPES = ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'json'];

    public function __construct(
        protected Encrypter $from,
        protected Encrypter $to,
        protected string $toKey,
    ) {}

    /** @return int how many values were re-encrypted */
    public function run(): int
    {
        $count = 0;

        foreach (Schema::getTables() as $table) {
            $name = (string) $table['name'];
            $primary = $this->primaryKey($name);

            if ($primary === null) {
                continue;
            }

            foreach (Schema::getColumns($name) as $column) {
                if (in_array(strtolower((string) $column['type_name']), self::TEXT_TYPES, true)) {
                    $count += $this->column($name, $primary, (string) $column['name']);
                }
            }
        }

        $this->rehashNationalCodes();

        return $count;
    }

    public function convert(string $value): string
    {
        return (string) preg_replace_callback(self::PAYLOAD, function (array $match): string {
            try {
                return $this->to->encrypt($this->from->decrypt($match[0], false), false);
            } catch (DecryptException) {
                return $match[0];
            }
        }, $value);
    }

    protected function column(string $table, string $primary, string $column): int
    {
        $count = 0;

        DB::table($table)
            ->select([$primary, $column])
            ->where($column, 'like', '%eyJpdiI6%')
            ->lazyById(500, $primary)
            ->each(function (object $row) use ($table, $primary, $column, &$count): void {
                $value = (string) $row->{$column};
                $converted = $this->convert($value);

                if ($converted !== $value) {
                    DB::table($table)->where($primary, $row->{$primary})->update([$column => $converted]);
                    $count++;
                }
            });

        return $count;
    }

    /** The one lookup hash kept with APP_KEY (AccountKycVerification). */
    protected function rehashNationalCodes(): void
    {
        if (! Schema::hasColumns('account_kyc_verifications', ['id', 'national_code_enc', 'national_code_hash'])) {
            return;
        }

        DB::table('account_kyc_verifications')
            ->select(['id', 'national_code_enc'])
            ->whereNotNull('national_code_enc')
            ->lazyById(500)
            ->each(function (object $row): void {
                try {
                    $plain = $this->to->decrypt((string) $row->national_code_enc, false);
                } catch (DecryptException) {
                    return;
                }

                DB::table('account_kyc_verifications')->where('id', $row->id)
                    ->update(['national_code_hash' => hash_hmac('sha256', $plain, $this->toKey)]);
            });
    }

    protected function primaryKey(string $table): ?string
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['primary'] && count($index['columns']) === 1) {
                return (string) $index['columns'][0];
            }
        }

        return null;
    }
}

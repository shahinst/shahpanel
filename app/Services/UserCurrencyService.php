<?php

namespace App\Services;

use App\Enums\MoneyCurrency;
use App\Enums\UserRole;
use App\Models\User;
use InvalidArgumentException;

class UserCurrencyService
{
    public function __construct(
        protected WalletService $walletService,
    ) {}

    /**
     * @return list<string>
     */
    public function enabledCodes(User $user): array
    {
        if ($user->role === UserRole::Admin) {
            return array_map(
                static fn (MoneyCurrency $currency): string => $currency->value,
                MoneyCurrency::sellable()
            );
        }

        $raw = $user->enabled_currencies;
        if (! is_array($raw) || $raw === []) {
            return [MoneyCurrency::IRT->value];
        }

        $codes = [];
        foreach ($raw as $code) {
            $currency = MoneyCurrency::tryFrom(strtoupper((string) $code));
            if ($currency !== null) {
                $codes[] = $currency->value;
            }
        }

        $codes = array_values(array_unique($codes));

        return $codes !== [] ? $codes : [MoneyCurrency::IRT->value];
    }

    /**
     * @return list<MoneyCurrency>
     */
    public function enabledCurrencies(User $user): array
    {
        return array_map(
            static fn (string $code): MoneyCurrency => MoneyCurrency::from($code),
            $this->enabledCodes($user)
        );
    }

    public function settlementCurrency(User $user): MoneyCurrency
    {
        if ($user->role === UserRole::Seller) {
            return MoneyCurrency::normalize($user->settlement_currency);
        }

        if ($user->role === UserRole::Agent) {
            $enabled = $this->enabledCodes($user);

            return MoneyCurrency::normalize($enabled[0] ?? MoneyCurrency::IRT->value);
        }

        return MoneyCurrency::default();
    }

    public function canUseCurrency(User $user, MoneyCurrency|string $currency): bool
    {
        $code = MoneyCurrency::normalize(
            $currency instanceof MoneyCurrency ? $currency->value : $currency
        )->value;

        if ($user->role === UserRole::Admin) {
            return true;
        }

        if ($user->role === UserRole::Seller) {
            return $this->settlementCurrency($user)->value === $code;
        }

        return in_array($code, $this->enabledCodes($user), true);
    }

    public function assertCanUseCurrency(User $user, MoneyCurrency|string $currency): void
    {
        $currencyEnum = MoneyCurrency::normalize(
            $currency instanceof MoneyCurrency ? $currency->value : $currency
        );

        if (! $this->canUseCurrency($user, $currencyEnum)) {
            throw new InvalidArgumentException(
                __('services.user_currency_not_allowed', ['name' => $user->full_name, 'currency' => $currencyEnum->label()])
            );
        }
    }

    /**
     * @param  list<string>|null  $enabledCurrencies
     */
    public function syncAgentCurrencies(User $agent, ?array $enabledCurrencies): void
    {
        if ($agent->role !== UserRole::Agent) {
            return;
        }

        $codes = $this->normalizeEnabledList($enabledCurrencies);
        $agent->forceFill(['enabled_currencies' => $codes])->save();

        foreach ($codes as $code) {
            $this->walletService->getOrCreateWallet($agent, $code);
        }
    }

    public function syncSellerSettlement(User $seller, ?string $settlementCurrency, ?User $parent = null): void
    {
        if ($seller->role !== UserRole::Seller) {
            return;
        }

        $parent ??= $seller->parent;
        $currency = MoneyCurrency::normalize($settlementCurrency);

        if ($parent !== null && $parent->role === UserRole::Agent) {
            $allowed = $this->enabledCodes($parent);
            if (! in_array($currency->value, $allowed, true)) {
                throw new InvalidArgumentException(
                    __('services.currency_not_enabled_for_parent', ['currency' => $currency->label()])
                );
            }
        }

        $seller->forceFill([
            'settlement_currency' => $currency->value,
            'enabled_currencies' => [$currency->value],
        ])->save();

        $this->walletService->getOrCreateWallet($seller, $currency);
    }

    /**
     * @param  list<string>|null  $enabledCurrencies
     * @return list<string>
     */
    public function normalizeEnabledList(?array $enabledCurrencies): array
    {
        $codes = [];
        foreach ($enabledCurrencies ?? [] as $code) {
            $currency = MoneyCurrency::tryFrom(strtoupper((string) $code));
            if ($currency !== null) {
                $codes[] = $currency->value;
            }
        }

        $codes = array_values(array_unique($codes));

        if ($codes === []) {
            $codes = [MoneyCurrency::IRT->value];
        }

        // Keep IRT first when present so legacy UI stays stable.
        usort($codes, static function (string $a, string $b): int {
            if ($a === MoneyCurrency::IRT->value) {
                return -1;
            }
            if ($b === MoneyCurrency::IRT->value) {
                return 1;
            }

            return strcmp($a, $b);
        });

        return $codes;
    }
}

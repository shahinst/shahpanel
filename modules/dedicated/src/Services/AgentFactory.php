<?php

namespace Modules\Dedicated\Services;

use App\Enums\MoneyCurrency;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Setting;
use App\Models\User;
use App\Services\UserCurrencyService;
use App\Services\WalletService;
use Illuminate\Support\Facades\Hash;

/**
 * Creates an agent the same way the regular agents page does, so a dedicated
 * or inbound agent is an ordinary agent in every other part of the panel.
 */
class AgentFactory
{
    /** Validation rules for the new-agent fields. */
    public static function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8'],
        ];
    }

    public function create(array $data, User $admin): User
    {
        $agent = User::query()->create([
            'full_name' => $data['full_name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make($data['password']),
            'role' => UserRole::Agent,
            'status' => UserStatus::Active,
            'parent_id' => $admin->id,
            'daily_server_change_limit' => (int) Setting::getValue('default_agent_daily_server_changes', 5),
            'settlement_currency' => MoneyCurrency::IRT->value,
        ]);

        app(UserCurrencyService::class)->syncAgentCurrencies($agent, [MoneyCurrency::IRT->value]);
        app(WalletService::class)->getOrCreateWallet($agent);

        return $agent;
    }
}

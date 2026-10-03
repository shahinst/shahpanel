<?php

namespace Modules\ShahBot\Services;

use App\Enums\MoneyCurrency;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\PackageDuration;
use App\Models\User;
use App\Services\ClientDisplayPricingService;
use App\Services\Pricing\ResellerDiscountService;
use App\Services\UserCurrencyService;
use App\Services\UserPackageAssignmentService;
use App\Services\UserPackagePricingService;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\ShahBot\Models\BotAgencyRequest;
use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\Support\BotSettings;
use Modules\ShahBot\Telegram\Keyboard;

/**
 * Agency requests. An approved bot user becomes a seller of the panel under
 * the bot's owner (who must be an agent), with the owner's packages at a
 * wholesale price below the bot's retail price. From then on the bot offers
 * them the reseller menu (wholesale and bulk buying), and they can log in to
 * the panel with the account the bot sends them.
 */
class AgencyService
{
    public function __construct(
        protected BotSettings $settings,
        protected BotUserService $users,
        protected BotNotifier $notifier,
    ) {}

    public function available(BotUser $user): bool
    {
        if (! $this->settings->bool('agency_enabled') || $user->reseller_user_id !== null) {
            return false;
        }

        try {
            return $this->users->owner($user)->role === UserRole::Agent;
        } catch (\Throwable) {
            return false;
        }
    }

    public function request(BotUser $user, string $note): BotAgencyRequest
    {
        if (! $this->available($user)) {
            throw new InvalidArgumentException(__('shahbot::bot.agency_closed'));
        }

        if (BotAgencyRequest::query()->where('bot_user_id', $user->id)->where('status', BotAgencyRequest::PENDING)->exists()) {
            throw new InvalidArgumentException(__('shahbot::bot.agency_pending'));
        }

        $request = BotAgencyRequest::query()->create([
            'bot_user_id' => $user->id,
            'note' => mb_substr(trim($note), 0, 2000),
            'status' => BotAgencyRequest::PENDING,
        ]);

        $this->notifier->admins(__('shahbot::bot.admin_agency_request', [
            'id' => $request->id,
            'user' => e($user->displayName()),
            'tg' => $user->telegram_id,
            'phone' => e($user->phone ?: '—'),
            'note' => e((string) $request->note),
        ]), Keyboard::inline([[
            Keyboard::button(__('shahbot::bot.btn_approve'), 'adm:ag:ok:'.$request->id),
            Keyboard::button(__('shahbot::bot.btn_reject'), 'adm:ag:no:'.$request->id),
        ]]));

        return $request;
    }

    public function approve(BotAgencyRequest $request, string $reviewer): BotAgencyRequest
    {
        [$request, $password] = DB::transaction(function () use ($request, $reviewer): array {
            $locked = BotAgencyRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== BotAgencyRequest::PENDING) {
                throw new InvalidArgumentException(__('shahbot::bot.agency_reviewed'));
            }

            $botUser = $locked->botUser;
            $agent = $this->users->owner($botUser);

            if ($agent->role !== UserRole::Agent) {
                throw new InvalidArgumentException(__('shahbot::bot.agency_closed'));
            }

            [$seller, $password] = $this->createSeller($botUser, $agent);

            $botUser->forceFill(['reseller_user_id' => $seller->id])->save();
            $locked->update([
                'status' => BotAgencyRequest::APPROVED,
                'seller_user_id' => $seller->id,
                'reviewed_by' => mb_substr($reviewer, 0, 128),
                'reviewed_at' => now(),
            ]);

            return [$locked, $password];
        });

        $seller = $request->seller;
        $this->notifier->user($request->botUser, __('shahbot::bot.agency_approved', [
            'url' => e(route('login')),
            'username' => e($seller->username),
            'password' => e($password),
        ]));

        return $request;
    }

    public function reject(BotAgencyRequest $request, string $reviewer): BotAgencyRequest
    {
        $updated = BotAgencyRequest::query()
            ->whereKey($request->id)
            ->where('status', BotAgencyRequest::PENDING)
            ->update(['status' => BotAgencyRequest::REJECTED, 'reviewed_by' => mb_substr($reviewer, 0, 128), 'reviewed_at' => now()]);

        if ($updated === 0) {
            throw new InvalidArgumentException(__('shahbot::bot.agency_reviewed'));
        }

        $this->notifier->user($request->botUser, __('shahbot::bot.agency_rejected'));

        return $request->fresh();
    }

    /**
     * @return array{0: User, 1: string}
     */
    protected function createSeller(BotUser $botUser, User $agent): array
    {
        $password = Str::password(12, symbols: false);

        do {
            $username = 'r'.Str::lower(Str::random(7));
        } while (User::query()->where('username', $username)->exists());

        $seller = User::query()->create([
            'role' => UserRole::Seller,
            'parent_id' => $agent->id,
            'username' => $username,
            'email' => $username.'@bot.shahpanel.local',
            'password' => Hash::make($password),
            'full_name' => $botUser->displayName(),
            'phone' => $botUser->phone,
            'status' => UserStatus::Active,
        ]);

        app(UserCurrencyService::class)->syncSellerSettlement($seller, MoneyCurrency::IRT->value, $agent);
        $seller->refresh();

        $packageIds = app(UserPackageAssignmentService::class)->assignedPackageIds($agent);
        app(UserPackageAssignmentService::class)->syncForUser($seller, $packageIds, $agent);

        if (! app(ResellerDiscountService::class)->isDiscountMode() && $packageIds !== []) {
            app(UserPackagePricingService::class)->syncForAssignedPackages($seller, $packageIds, $this->sellerPrices($agent, $packageIds), $agent);
        }

        app(WalletService::class)->getOrCreateWallet($seller);

        return [$seller, $password];
    }

    /**
     * The seller's wholesale price for each duration: the bot's retail price
     * less the agency discount, never below what the agent itself pays.
     *
     * @param  list<int>  $packageIds
     * @return array<int, array<int, string>>
     */
    protected function sellerPrices(User $agent, array $packageIds): array
    {
        $discount = max(0, min(100, (float) $this->settings->get('agency_discount')));
        $pricing = app(UserPackagePricingService::class);
        $retail = app(ClientDisplayPricingService::class)->managementCatalogForOwner($agent)
            ->keyBy(fn (array $row) => (int) $row['duration']->id);
        $prices = [];

        foreach (PackageDuration::query()->whereIn('package_id', $packageIds)->where('is_enabled', true)->get() as $duration) {
            $floor = (float) ($pricing->wholesalePriceFor($agent, $duration) ?? $duration->price);
            $shop = (float) ($retail[$duration->id]['display_price'] ?? $duration->price);
            $prices[$duration->package_id][$duration->id] = number_format(max($floor, round($shop * (1 - $discount / 100))), 2, '.', '');
        }

        return $prices;
    }
}

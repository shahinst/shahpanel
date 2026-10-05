<?php

namespace Modules\Dedicated;

use App\Enums\ServiceType;
use App\Models\InboundAllocation;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use App\Support\PanelExtensions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Dedicated\Console\MeterCommand;
use Modules\Dedicated\Models\DedicatedServer;
use Modules\Dedicated\Services\DedicatedPackageService;

/**
 * Dedicated agents and inbound resellers: agents who run on their own server
 * or on their own slice of one. Turning the module off removes both from the
 * panel; the data stays.
 */
class DedicatedServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'dedicated');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'dedicated');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Route::middleware('web')->group(__DIR__.'/../routes/web.php');

        // Only an agent who owns a dedicated server sees the entry.
        PanelExtensions::navItem(function (string $panel): ?array {
            if ($panel !== 'agent' || ! DedicatedServer::isDedicatedAgent(auth()->user())) {
                return null;
            }

            return [
                'label' => __('dedicated::admin.agent_menu'),
                'url' => route('agent.dedicated.index'),
                'icon' => 'bx-server',
                'active' => request()->routeIs('agent.dedicated.*'),
            ];
        });

        // An inbound agent sees their inbound's volume and can ask for more.
        PanelExtensions::navItem(function (string $panel): ?array {
            $user = auth()->user();

            if ($panel !== 'agent' || $user === null
                || ! InboundAllocation::query()->where('agent_user_id', $user->id)->exists()) {
                return null;
            }

            return [
                'label' => __('dedicated::admin.my_inbound'),
                'url' => route('agent.inbound-volume.index'),
                'icon' => 'bx-transfer-alt',
                'active' => request()->routeIs('agent.inbound-volume.*'),
            ];
        });

        // Either kind of agent sets what each of their sellers pays.
        PanelExtensions::navItem(function (string $panel): ?array {
            $user = auth()->user();

            if ($panel !== 'agent' || $user === null
                || ! Package::query()->where('owner_agent_id', $user->id)->exists()) {
                return null;
            }

            return [
                'label' => __('dedicated::admin.commissions'),
                'url' => route('agent.dedicated.commissions'),
                'icon' => 'bx-percent',
                'active' => request()->routeIs('agent.dedicated.commissions*'),
            ];
        });

        // A dedicated agent sells only on their own servers, so the accounts
        // menu shows just the kinds those servers carry. Their sellers sell
        // the same packages and get the same narrowing.
        PanelExtensions::accountCategories(function (string $panel, ?User $user): ?array {
            $agent = match ($panel) {
                'agent' => $user,
                'seller' => $user?->parent,
                default => null,
            };

            if ($agent === null) {
                return null;
            }

            // An inbound agent sells on the servers of their inbounds, a
            // dedicated agent on their own servers; both only there.
            $serverIds = array_merge(
                DedicatedServer::serverIdsOf($agent),
                InboundAllocation::query()->where('agent_user_id', $agent->id)->pluck('server_id')->all(),
            );

            if ($serverIds === []) {
                return null;
            }

            return Server::query()
                ->whereIn('id', $serverIds)
                ->get()
                ->flatMap(fn (Server $server): array => DedicatedPackageService::serviceTypesFor($server))
                ->map(fn (ServiceType $type): string => $type->accountCategory()->value)
                ->unique()
                ->values()
                ->all();
        });

        // Dedicated and inbound agents are managed on this module's pages, so
        // the regular agents list leaves them out.
        PanelExtensions::agentListExclusions(fn (): array => array_merge(
            DedicatedServer::query()->pluck('agent_user_id')->all(),
            InboundAllocation::query()->pluck('agent_user_id')->all(),
        ));

        if ($this->app->runningInConsole()) {
            $this->commands([MeterCommand::class]);

            $this->app->booted(function (): void {
                $this->app->make(Schedule::class)->command('dedicated:meter')
                    ->everyFiveMinutes()->withoutOverlapping(10)->name('dedicated.meter');
            });
        }
    }
}

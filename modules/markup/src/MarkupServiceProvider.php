<?php

namespace Modules\Markup;

use App\Enums\UserRole;
use App\Models\PackageDuration;
use App\Models\User;
use App\Support\PanelExtensions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class MarkupServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'markup');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'markup');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        Route::middleware('web')->group(__DIR__.'/../routes/web.php');

        PanelExtensions::sellerPriceRule(fn (User $seller, PackageDuration $duration): ?string => Markup::sellerPrice($seller, $duration));

        PanelExtensions::navItem(function (string $panel): ?array {
            $user = auth()->user();

            if ($panel === 'agent' && $user?->role === UserRole::Agent) {
                return ['label' => __('markup::markup.agent_menu'), 'url' => route('agent.markup.index'), 'icon' => 'bx-trending-up', 'active' => request()->routeIs('agent.markup.*')];
            }

            if ($panel === 'admin' && $user?->role === UserRole::Admin && admin_section_allowed('agents')) {
                return ['label' => __('markup::markup.admin_menu'), 'url' => route('admin.markup.index'), 'icon' => 'bx-trending-up', 'active' => request()->routeIs('admin.markup.*')];
            }

            return null;
        });
    }
}

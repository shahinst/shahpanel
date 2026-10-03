{{-- "My sales bot" for agents and sellers, while the bot module is on and the admin allows agent bots. --}}
@if (module_active('shahbot')
    && \Illuminate\Support\Facades\Route::has($panel.'.shahbot.my-bot')
    && class_exists(\Modules\ShahBot\Support\BotSettings::class)
    && rescue(fn () => app(\Modules\ShahBot\Support\BotSettings::class)->main('agent_bots_enabled') === '1', false, false))
    <x-sidebar-item
        :href="route($panel.'.shahbot.my-bot')"
        :label="__('shahbot::admin.my_bot')"
        icon="bxl-telegram"
        :active="request()->routeIs($panel.'.shahbot.*')" />
@endif

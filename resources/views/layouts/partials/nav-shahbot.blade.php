{{-- "My sales bot" for agents and sellers, while the bot module is on and this user has been given bot access. --}}
@if (module_active('shahbot')
    && \Illuminate\Support\Facades\Route::has($panel.'.shahbot.my-bot')
    && class_exists(\Modules\ShahBot\Support\BotSettings::class)
    && rescue(fn () => app(\Modules\ShahBot\Support\BotAccess::class)->allows(auth()->user()), false, false))
    <x-sidebar-item
        :href="route($panel.'.shahbot.my-bot')"
        :label="__('shahbot::admin.my_bot')"
        icon="bxl-telegram"
        :active="request()->routeIs($panel.'.shahbot.*')" />
@endif

{{--
    One-time welcome popup for the panel owner: star the project on GitHub.
    Closing it either way records it, so it is never shown again.
--}}
@php
    $starUser = auth()->user();
    $showStarPrompt = $starUser !== null
        && is_super_admin()
        && \App\Models\Setting::getValue(\App\Http\Controllers\Admin\PanelUpdateController::starPromptKey((int) $starUser->id)) === null;
    $starLinks = (array) config('shahpanel.author_links', []);
@endphp
@if ($showStarPrompt)
<div class="star-prompt" id="star-prompt" role="dialog" aria-modal="true" aria-labelledby="star-prompt-title"
     data-dismiss-url="{{ route('admin.star-prompt.dismiss') }}">
    <div class="star-prompt__card">
        <div class="star-prompt__sky" aria-hidden="true">
            <span>★</span><span>★</span><span>★</span><span>★</span><span>★</span><span>★</span>
        </div>
        <img src="{{ asset('images/shahpanel-logo.png') }}" alt="" class="star-prompt__logo">
        <h2 id="star-prompt-title">{{ __('updates.star_title') }}</h2>
        <p>{{ __('updates.star_text') }}</p>
        <a href="{{ $starLinks['github'] ?? 'https://github.com/shahinst/shahpanel' }}" target="_blank" rel="noopener" class="star-prompt__cta" data-star-close>
            <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M12 .5a11.5 11.5 0 0 0-3.64 22.41c.58.1.79-.25.79-.56v-2c-3.2.7-3.88-1.37-3.88-1.37-.53-1.33-1.29-1.69-1.29-1.69-1.05-.72.08-.7.08-.7 1.16.08 1.77 1.19 1.77 1.19 1.03 1.77 2.71 1.26 3.37.96.1-.75.4-1.26.73-1.55-2.55-.29-5.24-1.28-5.24-5.69 0-1.26.45-2.29 1.19-3.09-.12-.29-.52-1.46.11-3.05 0 0 .97-.31 3.17 1.18a11 11 0 0 1 5.77 0c2.2-1.49 3.17-1.18 3.17-1.18.63 1.59.23 2.76.11 3.05.74.8 1.19 1.83 1.19 3.09 0 4.42-2.7 5.39-5.26 5.68.41.36.78 1.06.78 2.14v3.17c0 .31.21.67.8.56A11.5 11.5 0 0 0 12 .5Z"/></svg>
            ⭐ {{ __('updates.star_button') }}
        </a>
        <div class="star-prompt__social">
            <span>{{ __('updates.star_follow') }}</span>
            <div>
                @if (! empty($starLinks['telegram']))<a href="{{ $starLinks['telegram'] }}" target="_blank" rel="noopener">Telegram</a>@endif
                @if (! empty($starLinks['youtube']))<a href="{{ $starLinks['youtube'] }}" target="_blank" rel="noopener">YouTube</a>@endif
            </div>
        </div>
        <button type="button" class="star-prompt__later" data-star-close>{{ __('updates.star_later') }}</button>
    </div>
</div>
<style>
    .star-prompt { position: fixed; inset: 0; z-index: 2000; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(8, 12, 28, .55); backdrop-filter: blur(4px); animation: star-fade .25s ease; }
    .star-prompt__card { position: relative; overflow: hidden; width: min(440px, 100%); text-align: center; padding: 32px 26px 22px; border-radius: 20px; color: #fff; background: radial-gradient(circle at 20% 0%, #3b8cff 0%, transparent 55%), linear-gradient(150deg, #1a2a6c 0%, #3a1c71 55%, #6d3fd6 100%); box-shadow: 0 24px 60px rgba(0, 0, 0, .35); animation: star-pop .35s cubic-bezier(.2, 1.4, .4, 1); }
    .star-prompt__logo { width: 76px; height: 76px; object-fit: contain; border-radius: 18px; background: rgba(255,255,255,.12); padding: 8px; margin-bottom: 12px; position: relative; }
    .star-prompt__card h2 { font-size: 1.35rem; font-weight: 800; margin: 0 0 8px; position: relative; color: #fff; }
    .star-prompt__card p { opacity: .92; line-height: 1.8; margin: 0 0 18px; position: relative; }
    .star-prompt__cta { position: relative; display: inline-flex; align-items: center; gap: 8px; padding: 12px 22px; border-radius: 12px; background: #fff; color: #24292f !important; font-weight: 800; text-decoration: none; box-shadow: 0 8px 22px rgba(0,0,0,.25); transition: transform .15s; }
    .star-prompt__cta:hover { transform: translateY(-2px) scale(1.02); }
    .star-prompt__social { position: relative; margin-top: 16px; font-size: .85rem; opacity: .9; }
    .star-prompt__social a { color: #ffd76a; margin: 0 6px; font-weight: 700; }
    .star-prompt__later { position: relative; margin-top: 14px; background: none; border: 0; color: rgba(255,255,255,.75); text-decoration: underline; cursor: pointer; }
    .star-prompt__sky span { position: absolute; color: #ffd76a; opacity: .0; animation: star-twinkle 3s ease-in-out infinite; font-size: 14px; }
    .star-prompt__sky span:nth-child(1) { top: 12%; left: 10%; animation-delay: 0s; }
    .star-prompt__sky span:nth-child(2) { top: 22%; right: 12%; animation-delay: .5s; font-size: 18px; }
    .star-prompt__sky span:nth-child(3) { top: 55%; left: 6%; animation-delay: 1s; }
    .star-prompt__sky span:nth-child(4) { bottom: 14%; right: 8%; animation-delay: 1.5s; font-size: 20px; }
    .star-prompt__sky span:nth-child(5) { top: 8%; left: 48%; animation-delay: 2s; }
    .star-prompt__sky span:nth-child(6) { bottom: 26%; left: 18%; animation-delay: 2.5s; font-size: 12px; }
    @keyframes star-twinkle { 0%, 100% { opacity: 0; transform: scale(.6) rotate(0); } 50% { opacity: 1; transform: scale(1.2) rotate(20deg); } }
    @keyframes star-pop { from { transform: scale(.85) translateY(20px); opacity: 0; } to { transform: none; opacity: 1; } }
    @keyframes star-fade { from { opacity: 0; } to { opacity: 1; } }
</style>
<script>
(function () {
    var prompt = document.getElementById('star-prompt');
    if (!prompt) return;
    var token = document.querySelector('meta[name="csrf-token"]');
    function dismiss() {
        prompt.remove();
        fetch(prompt.dataset.dismissUrl, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': token ? token.content : '', 'Accept': 'application/json' },
            credentials: 'same-origin'
        }).catch(function () {});
    }
    prompt.querySelectorAll('[data-star-close]').forEach(function (el) {
        el.addEventListener('click', dismiss);
    });
    prompt.addEventListener('click', function (e) { if (e.target === prompt) dismiss(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && document.body.contains(prompt)) dismiss(); });
})();
</script>
@endif

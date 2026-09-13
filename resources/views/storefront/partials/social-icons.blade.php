@php
    $accent = $storefront->accentColor();
    $links = array_filter([
        ['url' => $storefront->telegramUrl(), 'label' => 'Telegram', 'icon' => 'telegram'],
        ['url' => $storefront->instagramUrl(), 'label' => 'Instagram', 'icon' => 'instagram'],
        ['url' => $storefront->whatsappUrl(), 'label' => 'WhatsApp', 'icon' => 'whatsapp'],
    ]);
@endphp

@if (count($links))
    <div class="sf-social" role="list">
        @foreach ($links as $link)
            <a href="{{ $link['url'] }}" class="sf-social__btn sf-social__btn--{{ $link['icon'] }}" target="_blank" rel="noopener noreferrer" aria-label="{{ $link['label'] }}" role="listitem">
                @include('storefront.partials.social-svg', ['icon' => $link['icon']])
            </a>
        @endforeach
    </div>
@endif

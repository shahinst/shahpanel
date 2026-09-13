@php
    /** @var list<string> $assets */
    $assets = $assets ?? [];
    $resolved = panel_vite_tags($assets);
@endphp

@foreach ($resolved as $tag)
    @if ($tag['type'] === 'css')
        <link rel="stylesheet" href="{{ $tag['href'] }}" crossorigin>
    @elseif ($tag['type'] === 'preload')
        <link rel="modulepreload" href="{{ $tag['href'] }}" crossorigin>
    @else
        <script type="module" src="{{ $tag['href'] }}" crossorigin defer></script>
    @endif
@endforeach

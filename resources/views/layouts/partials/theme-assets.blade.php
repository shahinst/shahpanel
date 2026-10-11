{{-- The active theme's own files, after the panel's: a theme only layers on top. --}}
@php $theme = panel_theme(); @endphp
@if (($part ?? 'styles') === 'styles')
    @foreach (($theme['styles'] ?? []) as $href)
        <link rel="stylesheet" href="{{ $href }}">
    @endforeach
@else
    @foreach (($theme['scripts'] ?? []) as $src)
        <script defer src="{{ $src }}"></script>
    @endforeach
@endif

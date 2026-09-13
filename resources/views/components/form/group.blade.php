@props([
    'label' => null,
    'for' => null,
    'hint' => null,
    'wide' => false,
    'required' => false,
])

<div {{ $attributes->merge(['class' => 'mb-3 '.($wide ? 'col-12' : 'col-md-6')]) }}>
    @if ($label)
        <label class="form-label" @if($for) for="{{ $for }}" @endif>
            {{ $label }}@if($required)<span class="text-danger" aria-hidden="true"> *</span>@endif
        </label>
    @endif
    {{ $slot }}
    @if ($hint)
        <div class="form-text">{{ $hint }}</div>
    @endif
</div>

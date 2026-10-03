@props([
    'label' => null,
    'for' => null,
    'hint' => null,
    'wide' => false,
    'required' => false,
    // Field whose validation message is shown under the control. Defaults to
    // `for`, which is the input's id and, in these forms, also its name.
    'error' => null,
])

@php
    $errorKey = $error ?? $for;
    $body = (string) $slot;
    // Inputs that print their own @error message are left alone, so nothing
    // is shown twice.
    $errorMessage = $errorKey && isset($errors) && $errors->has($errorKey) && ! str_contains($body, 'invalid-feedback')
        ? $errors->first($errorKey)
        : null;
@endphp

<div {{ $attributes->merge(['class' => 'mb-3 '.($wide ? 'col-12' : 'col-md-6')]) }}>
    @if ($label)
        <label class="form-label" @if($for) for="{{ $for }}" @endif>
            {{ $label }}@if($required)<span class="text-danger" aria-hidden="true"> *</span>@endif
        </label>
    @endif
    {!! $body !!}
    @if ($errorMessage)
        <div class="invalid-feedback d-block">{{ $errorMessage }}</div>
    @endif
    @if ($hint)
        <div class="form-text">{{ $hint }}</div>
    @endif
</div>

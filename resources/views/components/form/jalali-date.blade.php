@props([
    'name',
    'value' => null,
    'required' => false,
    'placeholder' => '۱۴۰۳/۰۱/۱۵',
])

<input
    type="text"
    name="{{ $name }}"
    value="{{ old($name, $value) }}"
    @if($required) required @endif
    inputmode="numeric"
    autocomplete="off"
    placeholder="{{ $placeholder }}"
    dir="ltr"
    class="form-control text-end {{ $attributes->get('class') }}"
    {{ $attributes->except('class') }}
>

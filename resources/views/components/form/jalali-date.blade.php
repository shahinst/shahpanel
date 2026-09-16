@props([
    'name',
    'value' => null,
    'required' => false,
    'placeholder' => __('ui.jalali_date_placeholder'),
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

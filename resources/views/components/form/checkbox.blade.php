@props([
    'label',
    'name',
    'checked' => false,
    'value' => '1',
    'hiddenZero' => false,
    'wide' => false,
    'hint' => null,
])

<div {{ $attributes->merge(['class' => 'mb-3 '.($wide ? 'col-12' : 'col-md-6')]) }}>
    @if ($hiddenZero)
        <input type="hidden" name="{{ $name }}" value="0">
    @endif
    <div class="form-check mt-2">
        <input type="checkbox" class="form-check-input" id="chk_{{ $name }}" name="{{ $name }}" value="{{ $value }}" @checked($checked)>
        <label class="form-check-label" for="chk_{{ $name }}">{{ $label }}</label>
    </div>
    @if ($hint)
        <p class="help-block text-muted small mb-0 mt-1">{{ $hint }}</p>
    @endif
</div>

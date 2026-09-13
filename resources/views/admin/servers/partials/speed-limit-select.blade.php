@php
    $fieldName = $fieldName ?? 'speed_limit_mbps';
    $selected = old($fieldName, $selected ?? '');
    $inputId = $inputId ?? $fieldName;
    $formId = $formId ?? null;
@endphp
<select name="{{ $fieldName }}" id="{{ $inputId }}" class="form-select form-select-sm"
        @if($formId) form="{{ $formId }}" @endif>
    <option value="" @selected($selected === '' || $selected === null)>{{ __('servers.speed_limit_unlimited') }}</option>
    @foreach ([5, 10, 20, 30, 40, 50] as $mbps)
        <option value="{{ $mbps }}" @selected((string) $selected === (string) $mbps)>
            {{ persian_digits($mbps) }} {{ __('servers.speed_limit_mbps_unit') }}
        </option>
    @endforeach
</select>

{{-- A speed select that belongs to the form it is written in, and only that one.
     It used to accept a form id for the form="" attribute. Blade includes see the
     variables around them, so the row loop's $formId leaked into the create form
     below the table: its empty select joined the last row's save form, and that
     row's chosen speed was overwritten by "" -- read as "remove the limit" before
     3.23.0 and as a missing field after. --}}
@php
    $fieldName = $fieldName ?? 'speed_limit_mbps';
    $selected = old($fieldName, $selected ?? '');
    $inputId = $inputId ?? $fieldName;
@endphp
<select name="{{ $fieldName }}" id="{{ $inputId }}" class="form-select form-select-sm">
    <option value="" @selected($selected === '' || $selected === null)>{{ __('servers.speed_limit_unlimited') }}</option>
    @foreach ([5, 10, 20, 30, 40, 50] as $mbps)
        <option value="{{ $mbps }}" @selected((string) $selected === (string) $mbps)>
            {{ persian_digits($mbps) }} {{ __('servers.speed_limit_mbps_unit') }}
        </option>
    @endforeach
</select>

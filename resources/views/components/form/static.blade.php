@props(['label', 'value' => null])

<x-form.group :label="$label" {{ $attributes }}>
    <p class="form-control-plaintext mb-0">{{ $value ?? $slot }}</p>
</x-form.group>

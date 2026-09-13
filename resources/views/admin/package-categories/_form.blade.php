@php
    $category = $category ?? null;
@endphp

<x-form.group :label="__('packages.category_name')">
    <input name="name" value="{{ old('name', $category?->name) }}" required class="form-control">
</x-form.group>
<x-form.group :label="__('packages.category_sort_order')">
    <input name="sort_order" type="number" value="{{ old('sort_order', $category?->sort_order ?? 0) }}" class="form-control">
</x-form.group>
<x-form.checkbox name="is_active" :label="__('packages.category_active')" :checked="old('is_active', $category?->is_active ?? true)" :hiddenZero="true" />

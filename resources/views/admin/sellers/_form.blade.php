<x-form.group :label="__('sellers.parent')" :hint="__('sellers.parent_hint')">
    <select name="parent_id" required class="form-control">
        @foreach ($parents as $parent)
            <option value="{{ $parent->id }}"
                    data-enabled='@json($parent->enabledCurrencyCodes())'
                    @selected(old('parent_id', $seller?->parent_id ?? null) == $parent->id)>
                {{ $parent->full_name }}
            </option>
        @endforeach
    </select>
</x-form.group>

@include('shared.users._package_assignment')

@include('admin.users._form', ['user' => $seller ?? null])

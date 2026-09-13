@php $user = $user ?? auth()->user(); @endphp

<x-form.group :label="__('auth.username')">
    <input name="username" value="{{ old('username', $user?->username) }}" required class="form-control" dir="ltr">
</x-form.group>

<x-form.group :label="__('validation.attributes.full_name')">
    <input name="full_name" value="{{ old('full_name', $user?->full_name) }}" required class="form-control">
</x-form.group>

<x-form.group :label="__('auth.email')">
    <input name="email" type="email" value="{{ old('email', $user?->email) }}" required class="form-control" dir="ltr">
</x-form.group>

<x-form.group :label="__('validation.attributes.phone')">
    <input name="phone" value="{{ old('phone', $user?->phone) }}" class="form-control" dir="ltr">
</x-form.group>

<x-form.group :label="__('profile.telegram_id')" :hint="__('profile.telegram_id_hint')">
    <input name="telegram_id" value="{{ old('telegram_id', $user?->telegram_id) }}" class="form-control" dir="ltr">
</x-form.group>

<hr class="my-4">

<h6 class="mb-3">{{ __('profile.change_password') }}</h6>
<p class="text-muted small">{{ __('profile.change_password_hint') }}</p>

<x-form.group :label="__('profile.current_password')">
    <input name="current_password" type="password" class="form-control" autocomplete="current-password">
</x-form.group>

<x-form.group :label="__('auth.password')">
    <input name="password" type="password" class="form-control" autocomplete="new-password">
</x-form.group>

<x-form.group :label="__('profile.password_confirmation')">
    <input name="password_confirmation" type="password" class="form-control" autocomplete="new-password">
</x-form.group>

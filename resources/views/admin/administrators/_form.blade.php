@php
    /**
     * @var \App\Models\User|null $admin
     * @var array<string, array<string, mixed>> $availableSections
     * @var array<int, string> $grantedKeys
     * @var bool $isSuperTarget
     */
    $selected = old('sections', $grantedKeys);
    if (! is_array($selected)) {
        $selected = [];
    }
    $limitDefault = $grantedKeys !== [] ? '1' : '0';
    $limitSections = (string) old('limit_sections', $limitDefault) === '1';
@endphp

<x-form.group :label="__('auth.username')">
    <input name="username" value="{{ old('username', $admin?->username) }}" required class="form-control">
</x-form.group>
<x-form.group :label="__('auth.email')">
    <input name="email" type="email" value="{{ old('email', $admin?->email) }}" required class="form-control">
</x-form.group>
<x-form.group :label="__('validation.attributes.full_name')">
    <input name="full_name" value="{{ old('full_name', $admin?->full_name) }}" required class="form-control">
</x-form.group>
<x-form.group :label="__('validation.attributes.phone')">
    <input name="phone" value="{{ old('phone', $admin?->phone) }}" class="form-control">
</x-form.group>
<x-form.group :label="__('auth.password')">
    <input name="password" type="password" autocomplete="new-password" {{ $admin ? '' : 'required' }} class="form-control">
</x-form.group>
<x-form.group :label="__('ui.confirm_x', ['field' => __('auth.password')])">
    <input name="password_confirmation" type="password" autocomplete="new-password" class="form-control">
</x-form.group>
{{-- برای مدیر اصلی این فیلد را غیرفعال نمی‌کنیم: یک select غیرفعال هیچ مقداری
     نمی‌فرستد و اعتبارسنجی required را رد می‌کرد. کنترلر هر مقدارِ ارسالی را
     برای مدیر اصلی به Active برمی‌گرداند، پس تعلیقِ ناخواسته ممکن نیست. --}}
<x-form.group :label="__('app.status')">
    <select name="status" class="form-control">
        @foreach (\App\Enums\UserStatus::cases() as $status)
            <option value="{{ $status->value }}" @selected(old('status', $admin?->status?->value ?? \App\Enums\UserStatus::Active->value) === $status->value)>{{ $status->value }}</option>
        @endforeach
    </select>
</x-form.group>

<div class="col-12">
    <hr>
    <h5 class="mb-1">{{ __('admins.access_title') }}</h5>
    <p class="text-muted small mb-3">{{ __('admins.access_hint') }}</p>

    @if ($isSuperTarget)
        {{-- حسابِ مدیر اصلی: تیکی نشان نمی‌دهیم چون هیچ فهرستی برای او ذخیره
             نمی‌شود. نمایش یک فرمِ بی‌اثر فقط این توهم را می‌ساخت که می‌شود
             دسترسی صاحب پنل را کم کرد. --}}
        <div class="alert alert-info mb-0">
            <strong>{{ __('admins.super_admin') }}</strong><br>
            {{ __('admins.super_admin_hint') }}
        </div>
    @else
        <div class="form-check mb-2">
            <input type="radio" class="form-check-input" id="admin-access-full"
                   name="limit_sections" value="0" @checked(! $limitSections)>
            <label class="form-check-label" for="admin-access-full">{{ __('admins.access_full') }}</label>
        </div>
        <div class="form-check mb-2">
            <input type="radio" class="form-check-input" id="admin-access-limited"
                   name="limit_sections" value="1" @checked($limitSections)>
            <label class="form-check-label" for="admin-access-limited">{{ __('admins.access_limited') }}</label>
        </div>
        <p class="text-muted small">{{ __('admins.access_limited_hint') }}</p>

        <div id="admin-sections-list" @class(['mb-0', 'opacity-50' => ! $limitSections])>
            @foreach ($availableSections as $key => $section)
                @php
                    $always = ($section['always'] ?? false) === true;
                    $children = (array) ($section['children'] ?? []);
                @endphp
                <div class="border rounded p-2 mb-2">
                    <div class="form-check mb-0">
                        <input type="checkbox" class="form-check-input admin-section-parent"
                               id="admin-section-{{ $key }}"
                               name="sections[]" value="{{ $key }}"
                               @checked($always || in_array($key, $selected, true))
                               @disabled($always)>
                        <label class="form-check-label" for="admin-section-{{ $key }}">
                            <i class="bx {{ $section['icon'] ?? 'bx-folder' }} align-middle"></i>
                            <strong>{{ __($section['label']) }}</strong>
                        </label>
                    </div>
                    @if ($always)
                        <div class="text-muted small mt-1">{{ __('admins.dashboard_always') }}</div>
                    @endif
                    @if ($children !== [])
                        <div class="d-flex flex-wrap gap-3 mt-2 ps-4">
                            @foreach ($children as $childKey => $child)
                                <div class="form-check mb-0">
                                    <input type="checkbox" class="form-check-input admin-section-child"
                                           id="admin-section-{{ $childKey }}"
                                           name="sections[]" value="{{ $childKey }}"
                                           @checked(in_array($childKey, $selected, true))>
                                    <label class="form-check-label" for="admin-section-{{ $childKey }}">
                                        <i class="bx {{ $child['icon'] ?? 'bx-chevron-left' }} align-middle"></i>
                                        {{ __($child['label']) }}
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>

@unless ($isSuperTarget)
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var list = document.getElementById('admin-sections-list');
    var limited = document.getElementById('admin-access-limited');
    var full = document.getElementById('admin-access-full');
    if (!list || !limited || !full) return;

    function refresh() {
        // فقط ظاهری است: سمت سرور هم وقتی «دسترسی کامل» انتخاب شده باشد،
        // فهرست ارسالی را نادیده می‌گیرد و NULL ذخیره می‌کند.
        list.classList.toggle('opacity-50', !limited.checked);
        list.querySelectorAll('input[type="checkbox"]').forEach(function (box) {
            if (box.dataset.alwaysOn === '1') return;
            box.disabled = !limited.checked;
        });
    }

    list.querySelectorAll('input[type="checkbox"]:disabled').forEach(function (box) {
        box.dataset.alwaysOn = '1';
    });

    full.addEventListener('change', refresh);
    limited.addEventListener('change', refresh);
    refresh();
});
</script>
@endpush
@endunless

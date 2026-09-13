@php
    use App\Support\StorefrontSchema;
    $publicUrl = route('storefront.public', ['slug' => old('slug', $storefront->slug)]);
    $sfEnhanced = $storefrontEnhanced ?? StorefrontSchema::isEnhanced();
@endphp

<div class="panel-form-section">
    <form method="POST" action="{{ $action }}" class="storefront-settings-form">
        @csrf
        @method('PUT')

        @if ($storefrontMigrationPending ?? ! $sfEnhanced)
            <x-alert type="warning" class="mb-3">{{ __('storefront.migration_pending_notice') }}</x-alert>
        @endif

        <x-alert type="warning" class="mb-3">{{ __('storefront.forbidden_terms_notice') }}</x-alert>

        <div class="card border-0 shadow-none mb-3">
            <div class="card-body">
                <h6 class="text-muted text-uppercase small fw-bold mb-3">{{ __('storefront.section_brand') }}</h6>
                <div class="row g-3">
                    <x-form.group :label="__('validation.attributes.brand_name')">
                        <input name="brand_name" value="{{ old('brand_name', $storefront->brand_name) }}" required class="form-control">
                    </x-form.group>
                    @if ($sfEnhanced)
                        <x-form.group :label="__('storefront.tagline')">
                            <input name="tagline" value="{{ old('tagline', $storefront->tagline) }}" class="form-control" placeholder="{{ __('storefront.tagline_placeholder') }}">
                        </x-form.group>
                    @endif
                    <x-form.group :label="__('storefront.logo_url')">
                        <input name="logo_path" value="{{ old('logo_path', $storefront->logo_path) }}" class="form-control" dir="ltr" placeholder="https://...">
                        <span class="help-block">{{ __('storefront.logo_url_hint') }}</span>
                    </x-form.group>
                    <x-form.group :label="__('storefront.primary_color')">
                        <input name="primary_color" type="color" value="{{ old('primary_color', $storefront->accentColor()) }}" class="form-control" style="height:38px;padding:2px;">
                    </x-form.group>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-none mb-3">
            <div class="card-body">
                <h6 class="text-muted text-uppercase small fw-bold mb-3">{{ __('storefront.section_url') }}</h6>
                <div class="row g-3">
                    <x-form.group :label="__('validation.attributes.slug')">
                        <input name="slug" value="{{ old('slug', $storefront->slug) }}" required class="form-control" dir="ltr" pattern="[A-Za-z0-9_-]+" autocomplete="off">
                        <span class="help-block">{{ __('storefront.slug_hint') }}</span>
                    </x-form.group>
                    <div class="col-12">
                        <label class="form-label">{{ __('storefront.slug_preview') }}</label>
                        <div class="input-group" dir="ltr">
                            <input type="text" class="form-control bg-light" id="storefront-public-url" readonly value="{{ $publicUrl }}">
                            <button type="button" class="btn btn-outline-secondary" id="storefront-copy-url" data-copied="{{ __('storefront.copied') }}">
                                <i class="bx bx-copy"></i> {{ __('storefront.copy_link') }}
                            </button>
                            @if ($storefront->is_published)
                                <a href="{{ $storefront->publicUrl() }}" target="_blank" rel="noopener" class="btn btn-primary">
                                    <i class="bx bx-link-external"></i> {{ __('storefront.open_store') }}
                                </a>
                            @endif
                        </div>
                        @unless ($storefront->is_published)
                            <span class="help-block text-warning"><i class="bx bx-info-circle"></i> {{ __('storefront.not_published') }}</span>
                        @endunless
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-none mb-3">
            <div class="card-body">
                <h6 class="text-muted text-uppercase small fw-bold mb-3">{{ __('storefront.section_contact') }}</h6>
                <div class="row g-3">
                    <x-form.group :label="__('storefront.telegram')">
                        <input name="telegram_contact" value="{{ old('telegram_contact', $storefront->telegram_contact) }}" class="form-control" dir="ltr" placeholder="{{ __('storefront.telegram_placeholder') }}">
                    </x-form.group>
                    @if ($sfEnhanced)
                        <x-form.group :label="__('storefront.instagram')">
                            <input name="instagram_contact" value="{{ old('instagram_contact', $storefront->instagram_contact) }}" class="form-control" dir="ltr" placeholder="{{ __('storefront.instagram_placeholder') }}">
                        </x-form.group>
                        <x-form.group :label="__('storefront.whatsapp')">
                            <input name="whatsapp_contact" value="{{ old('whatsapp_contact', $storefront->whatsapp_contact) }}" class="form-control" dir="ltr" placeholder="{{ __('storefront.whatsapp_placeholder') }}">
                        </x-form.group>
                    @endif
                    <x-form.group :label="__('storefront.phone')">
                        <input name="phone_contact" value="{{ old('phone_contact', $storefront->phone_contact) }}" class="form-control" dir="ltr">
                    </x-form.group>
                    @if ($sfEnhanced)
                        <x-form.group :label="__('storefront.email')">
                            <input name="email_contact" type="email" value="{{ old('email_contact', $storefront->email_contact) }}" class="form-control" dir="ltr">
                        </x-form.group>
                        <x-form.group :label="__('storefront.website')">
                            <input name="website_url" type="url" value="{{ old('website_url', $storefront->website_url) }}" class="form-control" dir="ltr" placeholder="https://">
                        </x-form.group>
                    @endif
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-none mb-3">
            <div class="card-body">
                <h6 class="text-muted text-uppercase small fw-bold mb-3">{{ __('storefront.section_content') }}</h6>
                <div class="row g-3">
                    <x-form.group wide :label="__('storefront.description')">
                        <textarea name="description" rows="4" class="form-control" placeholder="{{ __('storefront.description_placeholder') }}">{{ old('description', $storefront->description) }}</textarea>
                    </x-form.group>
                    @if ($sfEnhanced)
                        <x-form.group wide :label="__('storefront.support_note')">
                            <textarea name="support_note" rows="3" class="form-control" placeholder="{{ __('storefront.support_note_placeholder') }}">{{ old('support_note', $storefront->support_note) }}</textarea>
                        </x-form.group>
                    @endif
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-none mb-3">
            <div class="card-body">
                <h6 class="text-muted text-uppercase small fw-bold mb-3">{{ __('storefront.section_publish') }}</h6>
                <x-form.checkbox name="is_published" :label="__('storefront.is_published')" :checked="old('is_published', $storefront->is_published)" />
            </div>
        </div>

        <x-form.actions>
            <x-button type="submit"><i class="bx bx-save"></i> {{ __('app.save') }}</x-button>
        </x-form.actions>
    </form>
</div>

<script>
(function () {
    const slugInput = document.querySelector('input[name="slug"]');
    const urlInput = document.getElementById('storefront-public-url');
    const copyBtn = document.getElementById('storefront-copy-url');
    const base = @json(url('/s/'));

    if (slugInput && urlInput) {
        slugInput.addEventListener('input', function () {
            const slug = slugInput.value.replace(/[^A-Za-z0-9_-]/g, '');
            urlInput.value = base + slug;
        });
    }

    if (copyBtn && urlInput) {
        copyBtn.addEventListener('click', function () {
            navigator.clipboard.writeText(urlInput.value).then(function () {
                const original = copyBtn.innerHTML;
                copyBtn.innerHTML = '<i class="bx bx-check"></i> ' + (copyBtn.dataset.copied || '');
                setTimeout(function () { copyBtn.innerHTML = original; }, 2000);
            });
        });
    }
})();
</script>

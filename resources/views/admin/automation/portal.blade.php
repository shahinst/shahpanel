@extends('layouts.panel')

@section('page_title', __('automation.portal_page_title'))

@section('panel_content')
<p class="text-muted mb-3">{{ __('automation.portal_page_hint') }}</p>

<form method="POST" action="{{ route('admin.automation.portal.update') }}" id="portal-form">
    @csrf
    @method('PUT')

    <div class="row g-3">
        <div class="col-12">
            <x-card :title="__('automation.portal_announcements')">
                <p class="text-muted small">{{ __('automation.portal_announcements_hint') }}</p>
                <div id="announcements-list" class="d-grid gap-3">
                    @forelse ($announcements as $i => $row)
                        @include('admin.automation._portal-announcement-row', ['index' => $i, 'row' => $row])
                    @empty
                        @include('admin.automation._portal-announcement-row', ['index' => 0, 'row' => ['id' => \Illuminate\Support\Str::uuid(), 'html' => '', 'enabled' => true, 'sort' => 0]])
                    @endforelse
                </div>
                <button type="button" class="btn btn-outline-secondary btn-sm mt-3" id="add-announcement">{{ __('automation.portal_add_announcement') }}</button>
            </x-card>
        </div>

        <div class="col-12">
            <x-card :title="__('automation.portal_suggested_apps')">
                <p class="text-muted small">{{ __('automation.portal_suggested_apps_hint') }}</p>
                <div id="categories-list" class="d-grid gap-3">
                    @forelse ($appCategories as $ci => $category)
                        @include('admin.automation._portal-app-category', ['cIndex' => $ci, 'category' => $category])
                    @empty
                        @include('admin.automation._portal-app-category', ['cIndex' => 0, 'category' => ['id' => \Illuminate\Support\Str::uuid(), 'name' => '', 'sort' => 0, 'icon_source_url' => '', 'icon_url' => '', 'apps' => []]])
                    @endforelse
                </div>
                <button type="button" class="btn btn-outline-secondary btn-sm mt-3" id="add-category">{{ __('automation.portal_add_category') }}</button>
            </x-card>
        </div>
    </div>

    <div class="d-flex gap-2 mt-3">
        <x-button type="submit">{{ __('app.save') }}</x-button>
        <label class="form-check ms-2 align-self-center">
            <input type="checkbox" class="form-check-input" name="refresh_icons" value="1">
            <span class="form-check-label small">{{ __('automation.portal_refresh_icons') }}</span>
        </label>
    </div>
</form>

<template id="announcement-template">
    @include('admin.automation._portal-announcement-row', ['index' => '__INDEX__', 'row' => ['id' => '__ID__', 'html' => '', 'enabled' => true, 'sort' => 0]])
</template>

<template id="category-template">
    @include('admin.automation._portal-app-category', ['cIndex' => '__CINDEX__', 'category' => ['id' => '__CID__', 'name' => '', 'sort' => 0, 'icon_source_url' => '', 'icon_url' => '', 'apps' => []]])
</template>

<template id="app-template">
    @include('admin.automation._portal-app-row', ['cIndex' => '__CINDEX__', 'aIndex' => '__AINDEX__', 'app' => ['id' => '__AID__', 'name' => '', 'url' => '', 'icon_url' => '']])
</template>
@endsection

@push('scripts')
<script>
(function () {
    let annIndex = {{ max(count($announcements), 1) }};
    let catIndex = {{ max(count($appCategories), 1) }};

    function bindEditor(box) {
        const editor = box.querySelector('[data-editor]');
        const hidden = box.querySelector('[data-html-input]');
        if (!editor || !hidden) return;

        box.querySelectorAll('[data-cmd]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const cmd = btn.getAttribute('data-cmd');
                const val = btn.getAttribute('data-value') || null;
                editor.focus();
                if (cmd === 'createLink') {
                    const url = prompt('URL');
                    if (url) document.execCommand(cmd, false, url);
                    return;
                }
                document.execCommand(cmd, false, val);
            });
        });

        const sync = function () { hidden.value = editor.innerHTML; };
        editor.addEventListener('input', sync);
        sync();
    }

    document.querySelectorAll('[data-announcement-row]').forEach(bindEditor);

    document.getElementById('add-announcement')?.addEventListener('click', function () {
        const tpl = document.getElementById('announcement-template').innerHTML
            .replace(/__INDEX__/g, String(annIndex++))
            .replace(/__ID__/g, crypto.randomUUID());
        const wrap = document.createElement('div');
        wrap.innerHTML = tpl.trim();
        const row = wrap.firstElementChild;
        document.getElementById('announcements-list').appendChild(row);
        bindEditor(row);
    });

    document.getElementById('add-category')?.addEventListener('click', function () {
        const tpl = document.getElementById('category-template').innerHTML
            .replace(/__CINDEX__/g, String(catIndex++))
            .replace(/__CID__/g, crypto.randomUUID());
        const wrap = document.createElement('div');
        wrap.innerHTML = tpl.trim();
        document.getElementById('categories-list').appendChild(wrap.firstElementChild);
    });

    document.addEventListener('click', function (e) {
        const addApp = e.target.closest('[data-add-app]');
        if (addApp) {
            const cIndex = addApp.getAttribute('data-add-app');
            const list = document.querySelector('[data-apps-list="' + cIndex + '"]');
            const aIndex = list.querySelectorAll('[data-app-row]').length;
            const tpl = document.getElementById('app-template').innerHTML
                .replace(/__CINDEX__/g, cIndex)
                .replace(/__AINDEX__/g, String(aIndex))
                .replace(/__AID__/g, crypto.randomUUID());
            const wrap = document.createElement('div');
            wrap.innerHTML = tpl.trim();
            list.appendChild(wrap.firstElementChild);
        }
    });

    document.getElementById('portal-form')?.addEventListener('submit', function () {
        document.querySelectorAll('[data-announcement-row]').forEach(function (row) {
            const editor = row.querySelector('[data-editor]');
            const hidden = row.querySelector('[data-html-input]');
            if (editor && hidden) hidden.value = editor.innerHTML;
        });
    });
})();
</script>
@endpush

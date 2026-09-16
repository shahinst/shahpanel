@extends('layouts.panel')

@section('page_title', __('ui.modules_page_title'))

@section('panel_content')
<div class="panel-modern-card mb-4">
    <div class="card-head">
        <h3><i class="bx bx-extension"></i> {{ __('ui.modules_add_new') }}</h3>
    </div>
    <div class="card-body">
        <p class="text-muted mb-3">
            {{ __('ui.modules_upload_hint_before') }} <code>.zip</code> {{ __('ui.modules_upload_hint_after') }}
        </p>

        <form action="{{ route('admin.modules.upload') }}" method="POST" enctype="multipart/form-data"
              class="d-flex flex-wrap align-items-center gap-2">
            @csrf
            <input type="file" name="module" accept=".zip" required
                   class="form-control" style="max-width: 360px;">
            <button type="submit" class="btn btn-primary">
                <i class="bx bx-upload"></i> {{ __('ui.modules_upload_install') }}
            </button>
        </form>

        @error('module')
            <div class="text-danger mt-2">{{ $message }}</div>
        @enderror
    </div>
</div>

<div class="panel-modern-card">
    <div class="card-head">
        <h3><i class="bx bx-cube"></i> {{ __('ui.modules_installed') }}</h3>
    </div>
    <div class="card-body">
        @if ($modules->isEmpty())
            <p class="text-muted mb-0">{{ __('ui.modules_none_installed') }}</p>
        @else
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>{{ __('ui.col_name') }}</th>
                            <th>{{ __('ui.col_version') }}</th>
                            <th>{{ __('ui.col_author') }}</th>
                            <th>{{ __('app.status') }}</th>
                            <th>{{ __('ui.col_installed_at') }}</th>
                            <th class="text-end">{{ __('app.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($modules as $module)
                            <tr>
                                <td>
                                    <div class="fw-bold">{{ $module->name }}</div>
                                    @if ($module->description)
                                        <div class="text-muted small">{{ $module->description }}</div>
                                    @endif
                                    <div class="text-muted small">{{ $module->slug }}</div>
                                </td>
                                <td>{{ persian_digits($module->version) }}</td>
                                <td>{{ $module->author ?: '—' }}</td>
                                <td>
                                    @if ($module->isActive())
                                        <span class="badge bg-success">{{ __('app.active') }}</span>
                                    @else
                                        <span class="badge bg-secondary">{{ __('app.inactive') }}</span>
                                    @endif
                                </td>
                                <td>{{ $module->installed_at ? jalali_date($module->installed_at) : '—' }}</td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1">
                                        @if ($module->isActive())
                                            <form action="{{ route('admin.modules.deactivate', $module) }}" method="POST">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                    <i class="bx bx-pause"></i> {{ __('ui.deactivate') }}
                                                </button>
                                            </form>
                                        @else
                                            <form action="{{ route('admin.modules.activate', $module) }}" method="POST">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-primary">
                                                    <i class="bx bx-play"></i> {{ __('ui.activate') }}
                                                </button>
                                            </form>
                                        @endif

                                        <form action="{{ route('admin.modules.destroy', $module) }}" method="POST"
                                              onsubmit='return confirm(@json(__("ui.modules_delete_confirm", [":name" => $module->name])));'>
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="bx bx-trash"></i> {{ __('app.delete') }}
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection

@extends('layouts.panel')

@section('page_title', __('tickets.departments'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('tickets.departments'),
    'subtitle' => __('tickets.departments_subtitle'),
    'icon' => 'bx-folder',
])

<div class="row g-3">
    <div class="col-lg-5">
        <div class="panel-form-section">
            <h4 class="panel-form-section-title">{{ __('tickets.add_department') }}</h4>
            <form method="POST" action="{{ route($panel.'.tickets.departments.store') }}">
                @csrf
                <x-form.group :label="__('tickets.department_name')">
                    <input name="name" value="{{ old('name') }}" required class="form-control">
                </x-form.group>
                <x-form.group wide :label="__('tickets.department_description')">
                    <textarea name="description" rows="3" class="form-control">{{ old('description') }}</textarea>
                </x-form.group>
                <x-form.actions>
                    <x-button type="submit">{{ __('app.save') }}</x-button>
                </x-form.actions>
            </form>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="panel-modern-card">
            <div class="card-head"><h3>{{ __('tickets.departments') }}</h3></div>
            <div class="card-body">
                @forelse ($departments as $department)
                    <div class="border rounded p-3 mb-3">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <div>
                                <div class="fw-semibold">{{ $department->name }}</div>
                                @if ($department->description)
                                    <div class="small text-muted mt-1">{{ $department->description }}</div>
                                @endif
                                <span class="badge {{ $department->is_active ? 'bg-success' : 'bg-secondary' }} mt-2">
                                    {{ $department->is_active ? __('tickets.department_active') : __('tickets.department_inactive') }}
                                </span>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#dept-edit-{{ $department->id }}">
                                {{ __('app.edit') }}
                            </button>
                        </div>

                        <div class="collapse" id="dept-edit-{{ $department->id }}">
                            <form method="POST" action="{{ route($panel.'.tickets.departments.update', $department) }}" class="border-top pt-3 mt-2">
                                @csrf
                                @method('PUT')
                                <x-form.group :label="__('tickets.department_name')">
                                    <input name="name" value="{{ old('name', $department->name) }}" required class="form-control">
                                </x-form.group>
                                <x-form.group wide :label="__('tickets.department_description')">
                                    <textarea name="description" rows="2" class="form-control">{{ old('description', $department->description) }}</textarea>
                                </x-form.group>
                                <x-form.group>
                                    <label class="form-check">
                                        <input type="checkbox" name="is_active" value="1" class="form-check-input" @checked(old('is_active', $department->is_active))>
                                        <span class="form-check-label">{{ __('tickets.department_active') }}</span>
                                    </label>
                                </x-form.group>
                                <div class="d-flex gap-2 flex-wrap">
                                    <x-button type="submit" size="sm">{{ __('app.save') }}</x-button>
                                </div>
                            </form>
                            <form method="POST" action="{{ route($panel.'.tickets.departments.destroy', $department) }}" class="mt-2" onsubmit="return confirm(@json(__('tickets.confirm_delete_department')));">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('app.delete') }}</button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="text-muted mb-0">{{ __('tickets.no_departments') }}</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection

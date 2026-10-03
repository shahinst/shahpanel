@extends('layouts.panel')

@section('page_title', __('shahbot::admin.tab_tutorials'))

@section('panel_content')
@include('shahbot::_nav')

<div class="sb-box">
    <header>{{ __('shahbot::admin.tutorial_new') }}</header>
    <div class="sb-body">
        <form method="POST" action="{{ route('admin.shahbot.tutorials.store') }}" class="row g-2">
            @csrf
            <div class="col-md-4"><input type="text" name="title" class="form-control" placeholder="{{ __('shahbot::admin.tutorial_title') }}" required maxlength="128"></div>
            <div class="col-md-5"><input type="url" name="url" class="form-control" dir="ltr" placeholder="{{ __('shahbot::admin.tutorial_url') }}"></div>
            <div class="col-md-1"><input type="number" name="sort_order" min="0" class="form-control" placeholder="{{ __('shahbot::admin.sort_order') }}"></div>
            <div class="col-md-12"><textarea name="body" rows="3" class="form-control" placeholder="{{ __('shahbot::admin.tutorial_body') }}" maxlength="3500"></textarea></div>
            <div class="col-md-2"><button class="btn btn-primary w-100">{{ __('shahbot::admin.save') }}</button></div>
        </form>
    </div>
</div>

@forelse ($tutorials as $tutorial)
    <div class="sb-box">
        <div class="sb-body">
            <form method="POST" action="{{ route('admin.shahbot.tutorials.update', $tutorial) }}" class="row g-2">
                @csrf
                @method('PUT')
                <div class="col-md-4"><input type="text" name="title" value="{{ $tutorial->title }}" class="form-control" required maxlength="128"></div>
                <div class="col-md-5"><input type="url" name="url" value="{{ $tutorial->url }}" class="form-control" dir="ltr" placeholder="{{ __('shahbot::admin.tutorial_url') }}"></div>
                <div class="col-md-1"><input type="number" name="sort_order" value="{{ $tutorial->sort_order }}" min="0" class="form-control"></div>
                <div class="col-md-2">
                    <input type="hidden" name="is_active" value="0">
                    <label class="form-check mt-2"><input type="checkbox" name="is_active" value="1" class="form-check-input" @checked($tutorial->is_active)> {{ __('shahbot::admin.active') }}</label>
                </div>
                <div class="col-md-12"><textarea name="body" rows="3" class="form-control" maxlength="3500">{{ $tutorial->body }}</textarea></div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-sm btn-primary">{{ __('shahbot::admin.save') }}</button>
                </div>
            </form>
            <form method="POST" action="{{ route('admin.shahbot.tutorials.destroy', $tutorial) }}" class="mt-2" data-confirm="{{ __('shahbot::admin.confirm_delete') }}">
                @csrf
                @method('DELETE')
                <button class="btn btn-sm btn-outline-danger"><i class="bx bx-trash"></i> {{ __('shahbot::admin.delete') }}</button>
            </form>
        </div>
    </div>
@empty
    <p class="sb-muted text-center">{{ __('shahbot::admin.empty') }}</p>
@endforelse
@endsection

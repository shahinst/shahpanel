@extends('layouts.panel')

@section('page_title', __('accounts.config_title'))

@section('panel_content')
<p class="margin-bottom">
    <a href="{{ route($prefix.'.accounts.'.$account->service_type->accountCategory()->value) }}" class="btn btn-link">&larr; {{ $account->service_type->accountCategory()->label() }}</a>
</p>

<x-alert type="error">{{ $error }}</x-alert>
<p class="help-block">سرور: {{ $account->server?->name ?? '—' }} — ابتدا از پنل ادمین «سینک اینترفیس‌ها» را اجرا کنید.</p>
@endsection

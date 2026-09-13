@extends('layouts.panel')

@section('page_title', 'راه‌اندازی تانلینگ')

@section('panel_content')
<div class="panel-modern-card mb-3 border-warning">
    <div class="card-head"><h3 class="text-warning mb-0">راه‌اندازی سیستم تانلینگ</h3></div>
    <div class="card-body">
        <p>کد تانلینگ آپلود شده اما دیتابیس یا فایل‌های لازم کامل نیست.</p>

        @if ($error)
            <div class="alert alert-danger mb-3"><strong>خطا:</strong> <code dir="ltr">{{ $error }}</code></div>
        @endif

        @if ($missingTables !== [])
            <div class="alert alert-warning mb-3">
                <strong>موارد ناقص:</strong>
                <code dir="ltr" class="d-block mt-2">{{ implode(', ', $missingTables) }}</code>
            </div>
        @endif

        <pre class="bg-light p-3 rounded small mb-0" dir="ltr">php artisan migrate --force
php artisan optimize:clear
php artisan tunneling:doctor</pre>
    </div>
</div>
@endsection

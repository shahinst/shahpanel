@extends('layouts.panel')

@section('page_title', 'ماژول‌ها')

@section('panel_content')
<div class="panel-modern-card mb-4">
    <div class="card-head">
        <h3><i class="bx bx-extension"></i> افزودن ماژول جدید</h3>
    </div>
    <div class="card-body">
        <p class="text-muted mb-3">
            فایل ماژول را با فرمت <code>.zip</code> انتخاب و آپلود کنید. پس از نصب، ماژول در حالت
            «غیرفعال» قرار می‌گیرد؛ برای استفاده باید آن را فعال کنید.
        </p>

        <form action="{{ route('admin.modules.upload') }}" method="POST" enctype="multipart/form-data"
              class="d-flex flex-wrap align-items-center gap-2">
            @csrf
            <input type="file" name="module" accept=".zip" required
                   class="form-control" style="max-width: 360px;">
            <button type="submit" class="btn btn-primary">
                <i class="bx bx-upload"></i> آپلود و نصب
            </button>
        </form>

        @error('module')
            <div class="text-danger mt-2">{{ $message }}</div>
        @enderror
    </div>
</div>

<div class="panel-modern-card">
    <div class="card-head">
        <h3><i class="bx bx-cube"></i> ماژول‌های نصب‌شده</h3>
    </div>
    <div class="card-body">
        @if ($modules->isEmpty())
            <p class="text-muted mb-0">هنوز هیچ ماژولی نصب نشده است.</p>
        @else
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>نام</th>
                            <th>نسخه</th>
                            <th>سازنده</th>
                            <th>وضعیت</th>
                            <th>تاریخ نصب</th>
                            <th class="text-end">عملیات</th>
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
                                        <span class="badge bg-success">فعال</span>
                                    @else
                                        <span class="badge bg-secondary">غیرفعال</span>
                                    @endif
                                </td>
                                <td>{{ $module->installed_at ? jalali_date($module->installed_at) : '—' }}</td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1">
                                        @if ($module->isActive())
                                            <form action="{{ route('admin.modules.deactivate', $module) }}" method="POST">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                    <i class="bx bx-pause"></i> غیرفعال‌سازی
                                                </button>
                                            </form>
                                        @else
                                            <form action="{{ route('admin.modules.activate', $module) }}" method="POST">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-primary">
                                                    <i class="bx bx-play"></i> فعال‌سازی
                                                </button>
                                            </form>
                                        @endif

                                        <form action="{{ route('admin.modules.destroy', $module) }}" method="POST"
                                              onsubmit="return confirm('حذف ماژول «{{ $module->name }}»؟ این عملیات فایل‌های ماژول را نیز پاک می‌کند.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="bx bx-trash"></i> حذف
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

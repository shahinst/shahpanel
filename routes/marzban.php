<?php

use App\Http\Controllers\Api\Marzban\MetaController;
use App\Http\Controllers\Api\Marzban\TokenController;
use App\Http\Controllers\Api\Marzban\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| نمای سازگار با مرزبان
|--------------------------------------------------------------------------
|
| این سطح کاملاً جدا از /api/v1 است و فقط برای این وجود دارد که ربات‌های فروش
| فارسی (میرزا و ویزویز) بتوانند «بدون هیچ تغییری در کد خودشان» این پنل را
| به‌عنوان یک پنل مرزبان ثبت کنند.
|
| مسیرها عیناً همان مسیرهای مرزبان‌اند: هیچ v1 یا پیشوند دیگری اضافه نمی‌شود،
| چون آدرس‌ها در درایور ربات‌ها ثابت (hard-coded) هستند. پیشوند api از
| bootstrap/app.php می‌آید، پس این فایل از داخل routes/api.php و بیرون از گروه
| v1 فراخوانی می‌شود.
|
| شکل خطا همه‌جا {"detail": "..."} با کد وضعیت واقعی است — همان چیزی که FastAPI
| (و در نتیجه مرزبان) می‌دهد و ربات‌ها می‌خوانند.
*/

// form-encoded، بدون احراز هویت. سقف بالا لازم است چون ویزویز توکن را کش
// نمی‌کند و پیش از هر عملیات دوباره لاگین می‌کند؛ محافظت از حملهٔ رمز جداگانه
// و بر اساس «شمارش شکست‌ها» داخل خودِ کنترلر انجام می‌شود.
Route::post('admin/token', [TokenController::class, 'store'])
    ->middleware('marzban.throttle:600,token')
    ->name('marzban.token');

Route::middleware(['marzban.auth', 'marzban.throttle:300,api'])->group(function (): void {
    // متادیتا: inbounds و system را میرزا می‌خواند، core/config را ویزویز.
    Route::get('inbounds', [MetaController::class, 'inbounds'])->name('marzban.inbounds');
    Route::get('system', [MetaController::class, 'system'])->name('marzban.system');
    Route::get('core/config', [MetaController::class, 'coreConfig'])->name('marzban.core.config');

    // باید بدون هیچ کوئری‌استرینگی هم جواب بدهد.
    Route::get('users', [UserController::class, 'index'])->name('marzban.users.index');

    Route::post('user', [UserController::class, 'store'])->name('marzban.users.store');

    // این دو پیش از user/{username} ثبت می‌شوند تا مسیر عمومی‌تر آن‌ها را
    // نبلعد؛ لاراول به ترتیب ثبت تطبیق می‌دهد.
    Route::post('user/{username}/reset', [UserController::class, 'reset'])->name('marzban.users.reset');
    Route::post('user/{username}/revoke_sub', [UserController::class, 'revokeSub'])->name('marzban.users.revoke');

    Route::get('user/{username}', [UserController::class, 'show'])->name('marzban.users.show');
    // ربات‌ها PUT می‌فرستند و کل شیء را پس می‌دهند، نه PATCH.
    Route::put('user/{username}', [UserController::class, 'update'])->name('marzban.users.update');
    Route::delete('user/{username}', [UserController::class, 'destroy'])->name('marzban.users.destroy');
});

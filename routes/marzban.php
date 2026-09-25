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

/*
| دسترسی‌های توکن این‌جا هم اعمال می‌شود، دقیقاً مثل /api/v1.
|
| پیش از این، گروه فقط marzban.auth داشت و هیچ‌کس can() توکن را صدا نمی‌زد؛ یعنی
| یک توکن عمداً باریک (مثلاً فقط wallet:read) می‌توانست با POST /api/user اکانت
| بسازد و کیف پول صاحب توکن را خرج کند. توانایی‌ها به صورت پارامتر به
| marzban.auth داده می‌شود (نه با api.ability) چون پاسخ ۴۰۳ باید همان
| {"detail": "..."} بماند؛ ربات‌ها فقط کلید detail را می‌خوانند.
|
| چند پارامتر یعنی «همه لازم است» (و نه یکی از آن‌ها) — توضیحش در
| MarzbanAuthenticate آمده است.
|
| ترتیب میان‌افزارها عوض نمی‌شود: marzban.auth پیش از marzban.throttle می‌آید تا
| سقف نرخ با شناسهٔ توکن شمرده شود، نه با IP مشترکِ پشت NAT.
*/

// متادیتا: inbounds و system را میرزا می‌خواند، core/config را ویزویز. دو مسیر
// اول فهرست پکیج‌ها و بازه‌های قابل فروش را نشان می‌دهند، یعنی همان دادهٔ
// /api/v1/catalog/*؛ پس catalog:read.
Route::middleware(['marzban.auth:catalog:read', 'marzban.throttle:300,api'])->group(function (): void {
    Route::get('inbounds', [MetaController::class, 'inbounds'])->name('marzban.inbounds');
    Route::get('core/config', [MetaController::class, 'coreConfig'])->name('marzban.core.config');
});

// system شمارش و مصرفِ اکانت‌های همین نماینده را می‌دهد، پس خواندنِ اکانت است.
Route::middleware(['marzban.auth:accounts:read', 'marzban.throttle:300,api'])->group(function (): void {
    Route::get('system', [MetaController::class, 'system'])->name('marzban.system');

    // باید بدون هیچ کوئری‌استرینگی هم جواب بدهد.
    Route::get('users', [UserController::class, 'index'])->name('marzban.users.index');
});

// فروش جدید: کیف پول کم می‌شود، پس همان accounts:create که /api/v1/accounts دارد.
Route::post('user', [UserController::class, 'store'])
    ->middleware(['marzban.auth:accounts:create', 'marzban.throttle:300,api'])
    ->name('marzban.users.store');

// این دو پیش از user/{username} ثبت می‌شوند تا مسیر عمومی‌تر آن‌ها را
// نبلعد؛ لاراول به ترتیب ثبت تطبیق می‌دهد.
//
// هیچ‌کدام پول نمی‌گیرد: reset فقط شمارنده‌ها را از پنل تازه می‌کند و
// revoke_sub توکن اشتراک را می‌چرخاند. هر دو «تغییر اکانت»اند، پس accounts:update.
Route::middleware(['marzban.auth:accounts:update', 'marzban.throttle:300,api'])->group(function (): void {
    Route::post('user/{username}/reset', [UserController::class, 'reset'])->name('marzban.users.reset');
    Route::post('user/{username}/revoke_sub', [UserController::class, 'revokeSub'])->name('marzban.users.revoke');
});

Route::get('user/{username}', [UserController::class, 'show'])
    ->middleware(['marzban.auth:accounts:read', 'marzban.throttle:300,api'])
    ->name('marzban.users.show');

// ربات‌ها PUT می‌فرستند و کل شیء را پس می‌دهند، نه PATCH.
//
// همین یک مسیر هم تمدیدِ پولی انجام می‌دهد (applyRenewal → renewAccount، کسر از
// کیف پول) و هم فعال/غیرفعال کردن و تغییر برچسب. کنترلر قابل تفکیک نیست، پس هر
// دو دسترسی لازم است؛ وگرنه توکنی با accounts:update می‌توانست خرید پولی راه
// بیندازد.
Route::put('user/{username}', [UserController::class, 'update'])
    ->middleware(['marzban.auth:accounts:update,accounts:renew', 'marzban.throttle:300,api'])
    ->name('marzban.users.update');

// حذف: کاتالوگ دسترسی‌ها accounts:delete ندارد و ساختنش توکن‌های صادرشده را
// بی‌اثر می‌کرد، پس نزدیک‌ترین دسترسی موجود یعنی accounts:update گذاشته شده است.
Route::delete('user/{username}', [UserController::class, 'destroy'])
    ->middleware(['marzban.auth:accounts:update', 'marzban.throttle:300,api'])
    ->name('marzban.users.destroy');

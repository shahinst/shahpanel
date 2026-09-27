<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AdminSectionAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/*
 * سدّ بخش‌های ادمین.
 *
 * عمداً میدل‌ور است و نه یک بررسی داخل هر کنترلر: با یک بار افزودن به گروهِ
 * مسیرهای ادمین، هر مسیر موجود و هر مسیر آینده پوشش داده می‌شود. پنهان‌کردنِ
 * آیتم منو فقط ظاهری است؛ چیزی که URL دستی را ۴۰۳ می‌کند همین است.
 */
class EnsureAdminSectionAccess
{
    public function __construct(protected AdminSectionAccessService $sections) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $routeName = $request->route()?->getName();

        // امروز هیچ مسیرِ بی‌نامی در گروه ادمین نیست. اگر روزی اضافه شد، نامِ
        // جعلی باعث می‌شود به هیچ بخشی نخورد و برای ادمینِ محدودشده بسته
        // بماند — بازگذاشتنِ ناخواسته خطرناک‌تر از یک ۴۰۳ قابل‌دیدن است.
        $routeName ??= '__unnamed__';

        if (! $this->sections->canAccessRoute($user instanceof User ? $user : null, $routeName)) {
            abort(403, __('admins.section_forbidden'));
        }

        return $next($request);
    }
}

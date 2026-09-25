<?php



namespace App\Http\Middleware;



use App\Support\PortalPaths;

use Closure;

use Illuminate\Http\Request;

use Symfony\Component\HttpFoundation\Response;



class BlockLegacyPortalPaths

{

    public function handle(Request $request, Closure $next): Response

    {

        $segment = $request->segment(1);



        if ($segment === null || ! PortalPaths::isLegacyBlockedPath($segment)) {

            return $next($request);

        }



        // این میدل‌ور «مسدود» می‌کند، نه «هدایت». نسخهٔ قبلی مسیر تصادفی ادمین را
        // با یک 301 به درخواست‌کننده تحویل می‌داد؛ یعنی هر کسی با زدن /admin
        // اسلاگ مخفی را می‌گرفت و تمام فایدهٔ تصادفی بودنش از بین می‌رفت.
        // امروز این کد اجرا نمی‌شود (مسیر قدیمی به هیچ route ی نمی‌خورد) ولی
        // اگر روزی به پشتهٔ عمومی منتقل شود نباید چیزی لو بدهد.
        abort(404);

    }

}



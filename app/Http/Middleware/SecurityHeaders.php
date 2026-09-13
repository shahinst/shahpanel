<?php



namespace App\Http\Middleware;



use Closure;

use Illuminate\Http\Request;

use Symfony\Component\HttpFoundation\Response;



class SecurityHeaders

{

    public function handle(Request $request, Closure $next): Response

    {

        /** @var Response $response */

        $response = $next($request);



        $embeddedApp = $request->session()->get('mypanel_embedded', false);

        if (! $embeddedApp) {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');

        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        $response->headers->set('X-XSS-Protection', '1; mode=block');

        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');



        if (! $response->headers->has('Cache-Control') && $request->user()) {

            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');

            $response->headers->set('Pragma', 'no-cache');

        }



        if ($request->secure()) {

            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

        }



        $csp = implode('; ', [

            "default-src 'self'",

            "base-uri 'self'",

            "form-action 'self'",

            $embeddedApp
                ? "frame-ancestors 'self' https://localhost capacitor://localhost http://localhost"
                : "frame-ancestors 'self'",

            "img-src 'self' data: blob: https:",

            "font-src 'self' data: https:",

            "style-src 'self' 'unsafe-inline' https:",

            "script-src 'self' 'unsafe-inline' https:",

            "connect-src 'self' https:",

            "object-src 'none'",

            "upgrade-insecure-requests",

        ]);

        $response->headers->set('Content-Security-Policy', $csp);



        return $response;

    }

}



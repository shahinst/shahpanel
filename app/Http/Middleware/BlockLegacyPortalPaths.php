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



        $paths = PortalPaths::all();

        $roleKey = $segment;

        $newSlug = $paths[$roleKey] ?? $segment;



        $remainder = $request->path();

        if (str_starts_with($remainder, $segment.'/')) {

            $remainder = substr($remainder, strlen($segment) + 1);

        } elseif ($remainder === $segment) {

            $remainder = '';

        }



        $target = '/'.$newSlug.($remainder !== '' ? '/'.$remainder : '');



        if ($request->getQueryString()) {

            $target .= '?'.$request->getQueryString();

        }



        return redirect($target, 301);

    }

}



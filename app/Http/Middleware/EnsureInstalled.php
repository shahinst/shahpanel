<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! shahpanel_installed()) {
            // There is no web installer any more — installing is install.sh or
            // `php artisan install:finalize`. This page is deliberately built
            // from a plain string rather than a view, because a half-deployed
            // panel may not have a usable session, cache or compiled view path.
            return response($this->notInstalledPage(), 503)
                ->header('Content-Type', 'text/html; charset=utf-8');
        }

        return $next($request);
    }

    protected function notInstalledPage(): string
    {
        $dir = locale_dir();
        $title = __('backend.not_installed_title');
        $heading = __('backend.not_installed_heading');
        $sshOnly = __('backend.not_installed_ssh_only');
        $manualHint = __('backend.not_installed_manual_hint', ['command' => '<code>migrate</code>']);

        return <<<HTML
            <!doctype html>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex, nofollow">
            <title>{$title}</title>
            <div style="font-family:system-ui,sans-serif;direction:{$dir};max-width:38rem;margin:4rem auto;padding:0 1rem;line-height:1.9;color:#1f2937">
                <h1 style="font-size:1.25rem;margin:0 0 1rem">{$heading}</h1>
                <p style="margin:0 0 .75rem">{$sshOnly}</p>
                <pre style="background:#f3f4f6;padding:.75rem 1rem;border-radius:.5rem;direction:ltr;overflow-x:auto">sudo bash install.sh panel.example.com</pre>
                <p style="margin:1rem 0 0">{$manualHint}</p>
                <pre style="background:#f3f4f6;padding:.75rem 1rem;border-radius:.5rem;direction:ltr;overflow-x:auto">php artisan install:finalize</pre>
            </div>
            HTML;
    }
}

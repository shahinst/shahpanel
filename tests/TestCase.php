<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // Modules are registered from the JSON cache during the application's
        // register phase, so a module the tests rely on has to be listed there
        // BEFORE the app is built — a migration or a DB row is far too late.
        $this->ensureBundledModulesAreActive();

        parent::setUp();

        if (! file_exists(config('vpnpanel.installed_lock'))) {
            file_put_contents(config('vpnpanel.installed_lock'), 'test');
        }
    }

    /**
     * Bundled modules ship inside the repo and are active on a real install
     * (the migration that registers them says so). Mirror that here so feature
     * tests see the same routes and views a real panel serves.
     */
    protected function ensureBundledModulesAreActive(): void
    {
        $bundled = ['tunneling', 'migrate'];
        $cacheFile = dirname(__DIR__).'/storage/app/modules_active.json';

        $entries = [];
        if (is_file($cacheFile)) {
            $decoded = json_decode((string) file_get_contents($cacheFile), true);
            $entries = is_array($decoded) ? $decoded : [];
        }

        $known = array_column($entries, 'slug');
        $changed = false;

        foreach ($bundled as $slug) {
            if (in_array($slug, $known, true)) {
                continue;
            }

            $manifestPath = dirname(__DIR__)."/modules/{$slug}/module.json";
            if (! is_file($manifestPath)) {
                continue;
            }

            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (! is_array($manifest)) {
                continue;
            }

            $entries[] = [
                'slug' => $manifest['slug'] ?? $slug,
                'namespace' => $manifest['namespace'] ?? null,
                'autoload' => $manifest['autoload'] ?? 'src',
                'provider' => $manifest['provider'] ?? null,
            ];
            $changed = true;
        }

        if ($changed) {
            @mkdir(dirname($cacheFile), 0775, true);
            @file_put_contents($cacheFile, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }
}

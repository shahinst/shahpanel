<?php

namespace App\Services\Modules;

use App\Models\Module;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * WordPress-style module (plugin) system for ShahPanel.
 *
 * A module is a .zip containing a module.json manifest plus its own code.
 * It is uploaded (extracted to /modules/{slug}, status = installed), then
 * activated (its service provider is booted and migrations run).
 *
 * Active modules are booted on every request via ModuleServiceProvider. To
 * avoid touching the database during the (pre-DB) provider-register phase, the
 * set of active modules is kept in a small JSON cache that is rewritten on
 * every install / activate / deactivate / delete.
 */
class ModuleManager
{
    /** Namespaces we already registered an autoloader for (per request). */
    protected array $autoloaded = [];

    public function modulesPath(?string $slug = null): string
    {
        $base = base_path('modules');

        return $slug ? $base.DIRECTORY_SEPARATOR.$slug : $base;
    }

    protected function cacheFile(): string
    {
        return storage_path('app/modules_active.json');
    }

    /**
     * Boot all active modules from the JSON cache. Called from
     * ModuleServiceProvider::register() — must NOT hit the database here.
     */
    public function registerActiveModules(Application $app): void
    {
        foreach ($this->cachedActiveModules() as $entry) {
            try {
                $this->registerModuleEntry($app, $entry);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Is the given module currently active? Reads the JSON cache (no DB), so it
     * is safe to call from anywhere (views, menus, feature gates).
     */
    public function isActive(string $slug): bool
    {
        foreach ($this->cachedActiveModules() as $entry) {
            if (($entry['slug'] ?? null) === $slug) {
                return true;
            }
        }

        return false;
    }

    protected function cachedActiveModules(): array
    {
        $file = $this->cacheFile();
        if (! is_file($file)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Rebuild the active-modules JSON cache from the database. Safe to call any
     * time the DB is available (activate / deactivate / install / delete).
     */
    public function refreshCache(): void
    {
        try {
            if (! Schema::hasTable('modules')) {
                return;
            }
            $modules = Module::query()->where('status', Module::STATUS_ACTIVE)->get();
        } catch (\Throwable $e) {
            return;
        }

        $entries = $modules->map(fn (Module $m): array => [
            'slug' => $m->slug,
            'namespace' => $m->manifest['namespace'] ?? null,
            'autoload' => $m->manifest['autoload'] ?? 'src',
            'provider' => $m->manifest['provider'] ?? $m->provider,
        ])->values()->all();

        File::ensureDirectoryExists(dirname($this->cacheFile()));
        file_put_contents(
            $this->cacheFile(),
            json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    protected function registerModuleEntry(Application $app, array $entry): void
    {
        $slug = $entry['slug'] ?? null;
        if (! is_string($slug) || $slug === '') {
            return;
        }

        $dir = $this->modulesPath($slug);
        if (! is_dir($dir)) {
            return;
        }

        $namespace = $entry['namespace'] ?? null;
        if (is_string($namespace) && $namespace !== '') {
            $autoloadDir = $dir.DIRECTORY_SEPARATOR.($entry['autoload'] ?? 'src');
            $this->registerAutoloader($namespace, $autoloadDir);
        }

        $provider = $entry['provider'] ?? null;
        if (is_string($provider) && $provider !== '' && class_exists($provider)) {
            $app->register($provider);
        }
    }

    protected function registerAutoloader(string $namespace, string $baseDir): void
    {
        $prefix = trim($namespace, '\\').'\\';
        if (isset($this->autoloaded[$prefix])) {
            return;
        }
        $this->autoloaded[$prefix] = true;

        $baseDir = rtrim($baseDir, '/\\').DIRECTORY_SEPARATOR;

        spl_autoload_register(function (string $class) use ($prefix, $baseDir): void {
            if (! str_starts_with($class, $prefix)) {
                return;
            }
            $relative = substr($class, strlen($prefix));
            $file = $baseDir.str_replace('\\', DIRECTORY_SEPARATOR, $relative).'.php';
            if (is_file($file)) {
                require $file;
            }
        });
    }

    /**
     * Install a module from an uploaded .zip file. Returns the Module record
     * (status = installed / inactive). Existing module with same slug is replaced.
     */
    public function installFromZip(string $zipPath): Module
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('افزونه‌ی Zip در PHP فعال نیست.');
        }

        $tmp = storage_path('app/modules_tmp/'.Str::random(20));
        File::ensureDirectoryExists($tmp);

        try {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException('فایل زیپ قابل باز کردن نیست.');
            }

            // Guard against path traversal / absolute paths inside the archive.
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                if ($entry === false) {
                    continue;
                }
                if (str_contains($entry, '..') || str_starts_with($entry, '/') || preg_match('#^[A-Za-z]:#', $entry)) {
                    $zip->close();
                    throw new RuntimeException('مسیر نامعتبر در آرشیو: '.$entry);
                }
            }

            $zip->extractTo($tmp);
            $zip->close();

            $root = $this->locateManifestDir($tmp);
            if ($root === null) {
                throw new RuntimeException('فایل module.json در آرشیو پیدا نشد.');
            }

            $manifest = $this->readManifest($root);
            $slug = $manifest['slug'];

            $existing = Module::where('slug', $slug)->first();

            $dest = $this->modulesPath($slug);
            if (is_dir($dest)) {
                File::deleteDirectory($dest);
            }
            File::ensureDirectoryExists(dirname($dest));
            File::moveDirectory($root, $dest);

            $module = Module::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $manifest['name'] ?? $slug,
                    'version' => $manifest['version'] ?? '1.0.0',
                    'description' => $manifest['description'] ?? null,
                    'author' => $manifest['author'] ?? null,
                    'provider' => $manifest['provider'] ?? null,
                    'manifest' => $manifest,
                    'status' => $existing?->status ?? Module::STATUS_INSTALLED,
                    'installed_at' => $existing?->installed_at ?? now(),
                    'activated_at' => $existing?->activated_at,
                ]
            );

            $this->refreshCache();

            return $module;
        } finally {
            File::deleteDirectory($tmp);
        }
    }

    public function activate(Module $module): void
    {
        $module->update([
            'status' => Module::STATUS_ACTIVE,
            'activated_at' => now(),
        ]);

        $this->refreshCache();

        $relativeMigrations = 'modules/'.$module->slug.'/database/migrations';
        if (is_dir(base_path($relativeMigrations))) {
            Artisan::call('migrate', [
                '--path' => $relativeMigrations,
                '--force' => true,
            ]);
        }

        $this->clearCaches();
    }

    public function deactivate(Module $module): void
    {
        $module->update([
            'status' => Module::STATUS_INSTALLED,
            'activated_at' => null,
        ]);

        $this->refreshCache();
        $this->clearCaches();
    }

    public function delete(Module $module): void
    {
        $dir = $this->modulesPath($module->slug);
        if (is_dir($dir)) {
            File::deleteDirectory($dir);
        }
        $module->delete();

        $this->refreshCache();
        $this->clearCaches();
    }

    protected function locateManifestDir(string $base): ?string
    {
        if (is_file($base.'/module.json')) {
            return $base;
        }
        foreach (glob($base.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (is_file($dir.'/module.json')) {
                return $dir;
            }
        }

        return null;
    }

    protected function readManifest(string $dir): array
    {
        $raw = @file_get_contents($dir.'/module.json');
        $manifest = json_decode((string) $raw, true);

        if (! is_array($manifest)) {
            throw new RuntimeException('محتوای module.json نامعتبر است.');
        }

        $slug = $manifest['slug'] ?? null;
        if (! is_string($slug) || ! preg_match('/^[a-z0-9\-]+$/', $slug)) {
            throw new RuntimeException('شناسه‌ی (slug) ماژول نامعتبر است؛ فقط حروف کوچک انگلیسی، عدد و خط تیره مجاز است.');
        }

        if (empty($manifest['name']) || ! is_string($manifest['name'])) {
            throw new RuntimeException('نام ماژول در module.json الزامی است.');
        }

        return $manifest;
    }

    protected function clearCaches(): void
    {
        try {
            Artisan::call('view:clear');
            Artisan::call('route:clear');
        } catch (\Throwable $e) {
            // best effort
        }
    }
}

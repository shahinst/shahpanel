<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Tests run against a migrated database, which is what "installed" means
        // to the panel; the lock file it checks lives outside the repository.
        $lock = (string) config('shahpanel.installed_lock');
        if ($lock !== '' && ! file_exists($lock)) {
            @mkdir(dirname($lock), 0775, true);
            @touch($lock);
        }

        // No test may reach a real server, panel or GitHub.
        Http::preventStrayRequests();
    }
}

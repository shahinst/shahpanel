<?php

namespace Tests\Concerns;

use Modules\Dedicated\DedicatedServiceProvider;

/**
 * The dedicated-agents module is not active in the test install, yet its
 * routes (including the inbound-reseller ones it now owns) are what these
 * tests drive, so it is booted by hand.
 */
trait BootsDedicatedModule
{
    protected function bootDedicatedModule(): void
    {
        spl_autoload_register(function (string $class): void {
            if (str_starts_with($class, 'Modules\\Dedicated\\')) {
                $file = base_path('modules/dedicated/src/'.str_replace('\\', '/', substr($class, strlen('Modules\\Dedicated\\'))).'.php');
                if (is_file($file)) {
                    require_once $file;
                }
            }
        });

        $this->app->register(DedicatedServiceProvider::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
    }
}

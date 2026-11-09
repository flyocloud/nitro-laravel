<?php

namespace Flyo\Laravel\Tests\Fakes;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers a fallback route while booting after the package, the way `routes/web.php` of an
 * application is loaded after the packages booted.
 */
class AppFallbackProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('web')->group(fn () => Route::fallback(fn () => 'application fallback'));
    }
}

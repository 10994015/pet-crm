<?php

namespace App\Providers;

use BladeUI\Icons\Factory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(Factory::class, function (Factory $icons) {
            $icons->add('bibi', ['path' => resource_path('svg'), 'prefix' => 'bibi']);
        });
    }

    public function boot(): void
    {
        //
    }
}

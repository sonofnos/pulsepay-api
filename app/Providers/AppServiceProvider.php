<?php

namespace App\Providers;

use App\Services\Vtu\FakeVtuProvider;
use App\Services\Vtu\VtuProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(VtuProvider::class, FakeVtuProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        Sanctum::$accessTokenAuthenticationCallback = function ($accessToken, $isValid) {
            return !$accessToken->last_used_at || $accessToken->last_used_at->gte(now()->subMinutes(5));
        };
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}

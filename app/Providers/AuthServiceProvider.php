<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        // 'App\Models\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        // Laravel 10+ upgrade: registerPolicies() is now invoked
        // automatically by the framework, so the manual call here was
        // removed (harmless if left, but redundant — see
        // docs/ops/STACK_UPGRADE_GUIDE.md).

        //
    }
}

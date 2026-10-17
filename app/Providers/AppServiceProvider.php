<?php

namespace App\Providers;

use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
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
        // SEC-11: active ethical screens per user, loaded once per request/job.
        $this->app->scoped(\App\Matters\WallRegistry::class);

        // PLAT-1: the one place deployment mode picks an entitlement source.
        $this->app->singleton(\App\Entitlements\EntitlementSource::class, fn ($app) => config('tenancy.mode') === 'multi'
            ? $app->make(\App\Entitlements\TenantPlanSource::class)
            : $app->make(\App\Entitlements\LicensePlanSource::class));
        $this->app->singleton(\App\Licensing\LicenseVerifier::class, fn () => new \App\Licensing\LicenseVerifier(
            array_values((array) config('entitlements.license.public_keys')),
            (int) config('entitlements.license.default_grace_days'),
        ));
        $this->app->singleton(\App\Licensing\LicenseManager::class);
        // FUN-11: gateways resolved from the container collect for the firm (its own accounts); PLAT-4 builds its own
        $this->app->bind(\App\Payments\Credentials::class, \App\Secrets\IntegrationCredentials::class);
        $this->app->singleton(\App\Observability\Tracer::class);          // ENG-24
        $this->app->singleton(\App\Observability\ErrorReporter::class);
        $this->app->singleton(\App\Entitlements\Entitlements::class);
        $this->app->singleton(\App\Database\ReadReplica::class);
        // ENG-19: the search engine (config/search.php); the database engine searches without one
        $this->app->singleton(\App\Search\SearchEngine::class, fn () => match (config('search.engine')) {
            'meilisearch' => new \App\Search\MeilisearchEngine,
            default => new \App\Search\DatabaseEngine,
        });   // ENG-23: remembers replica health between reads

        // PLAT-3: flags, same rule: only here does the deployment mode choose where tenant overrides come from.
        $this->app->singleton(\App\Flags\FlagOverrides::class, fn ($app) => config('tenancy.mode') === 'multi'
            ? $app->make(\App\Flags\TenantFlagOverrides::class)
            : $app->make(\App\Flags\NoTenantOverrides::class));
        $this->app->singleton(\App\Flags\Flags::class);

        $this->app->singleton(\App\Tenancy\TenantContext::class);
        $this->app->singleton(\App\Tenancy\TenantManager::class);
        $this->app->singleton(\App\Tenancy\TenantStorageCredentials::class);   // TEN-12: remembers base disk config and per-firm providers

        Sanctum::$accessTokenAuthenticationCallback = function ($accessToken, $isValid) {
            return ! $accessToken->last_used_at || $accessToken->last_used_at->gte(now()->subMinutes(5));
        };
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        \App\Search\SearchObserver::register();   // ENG-19: keep the firm's search indexes current
        \App\Observability\Instrumentation::register();   // ENG-24: database and job spans (only with tracing on)

        // TEN-10: on a Redis shared by firms, a flush clears only the active firm's keys (Laravel's runs FLUSHDB)
        \Illuminate\Support\Facades\Cache::extend('redis', function ($app, array $config) {
            $connection = $config['connection'] ?? 'default';
            $store = new \App\Tenancy\TenantRedisStore($app['redis'], $config['prefix'] ?? $app['config']['cache.prefix'], $connection, $app['config']['cache.serializable_classes'] ?? null);

            return $this->repository($store->setLockConnection($config['lock_connection'] ?? $connection), $config);
        });

        // TEN-10: logins are per firm (a session replayed on another firm's host must not authenticate there).
        \Illuminate\Support\Facades\Auth::extend('session', function ($app, $name, array $config) {
            $guard = new \App\Tenancy\TenantSessionGuard(
                $name,
                \Illuminate\Support\Facades\Auth::createUserProvider($config['provider'] ?? null),
                $app['session.store'],
                rehashOnLogin: $app['config']->get('hashing.rehash_on_login', true),
                timeboxDuration: $app['config']->get('auth.timebox_duration', 200000),
                hashKey: $app['config']->get('app.key'),
            );
            $guard->setCookieJar($app['cookie']);
            $guard->setDispatcher($app['events']);
            $guard->setRequest($app->refresh('request', $guard, 'setRequest'));
            if (isset($config['remember'])) {
                $guard->setRememberDuration($config['remember']);
            }

            return $guard;
        });

        // TEN-6: namespace cache/permission-cache keys for the active tenant.
        app(\App\Tenancy\TenantContext::class)->activate(app(\App\Tenancy\TenantContext::class)->key());

        // SEC-13: nothing under a legal hold can be deleted (soft or hard), whoever asks and however they get there.
        foreach ([\App\Models\Client::class, \App\Models\Cases::class, \App\Models\Document::class] as $model) {
            $model::deleting(fn ($m) => app(\App\Compliance\LegalHolds::class)->guard($m));
        }

        // PLAT-11: model events -> webhook deliveries
        \App\Webhooks\WebhookService::register();

        // ENG-24: once someone is authenticated, their id rides on every log line.
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Auth\Events\Authenticated::class,
            fn ($event) => \Illuminate\Support\Facades\Context::add('user_id', $event->user->getAuthIdentifier())
        );

        // TEN-3: every queued job carries its tenant and restores it before
        // running, so a job serialized under tenant A can never execute
        // against tenant B, whatever the worker's ambient context is.
        Queue::createPayloadUsing(function ($connection, $queue, $payload) {
            // TEN-7: fleet jobs from the control plane belong to no firm (see App\Tenancy\PlatformJob).
            $command = $payload['data']['command'] ?? null;
            if (is_object($command) && $command instanceof \App\Tenancy\PlatformJob) {
                return ['tenant' => null, 'platform' => true];
            }

            return ['tenant' => app(\App\Tenancy\TenantContext::class)->key()];
        });

        Queue::before(function (JobProcessing $event) {
            // TEN-17: whatever runs the job (a worker, or a request through the sync queue) gets its firm state back after
            app(\App\Tenancy\TenantManager::class)->pushState();
            // PLAT-1: a long-lived worker must not keep serving a stale plan.
            app(\App\Entitlements\Entitlements::class)->forget();
            app(\App\Flags\Flags::class)->forget();

            $payload = $event->job->payload();
            $tenant = $payload['tenant'] ?? null;
            $manager = app(\App\Tenancy\TenantManager::class);

            // TEN-7: a platform job runs in no firm; the job itself activates the firm it works on. Only trusted when the
            // class really is a PlatformJob, so a payload cannot opt itself out of the firm check.
            if (($payload['platform'] ?? false) === true && is_subclass_of((string) ($payload['data']['commandName'] ?? ''), \App\Tenancy\PlatformJob::class)) {
                if ($manager->isMulti()) {
                    $manager->failClosed();
                }

                return;
            }

            if ($tenant === null) {
                if ($manager->isMulti()) {
                    throw new \RuntimeException('Queued job has no tenant; refusing to run it.');
                }

                return;
            }

            $manager->activateBySlug($tenant);
        });

        // After a job (done or failed): the state before it. A worker had no firm, so it is fail-closed again; a request
        // that ran the job inline keeps its own firm.
        Queue::after(fn (JobProcessed $event) => app(\App\Tenancy\TenantManager::class)->popState());
        Queue::exceptionOccurred(fn (\Illuminate\Queue\Events\JobExceptionOccurred $event) => app(\App\Tenancy\TenantManager::class)->popState());

        // Multi mode: nothing may query a database until a tenant is resolved.
        if (app(\App\Tenancy\TenantManager::class)->isMulti()) {
            app(\App\Tenancy\TenantManager::class)->useControlPlaneQueue();   // TEN-2
            app(\App\Tenancy\TenantManager::class)->failClosed();
        }
    }
}

<?php

/**
 * ENG-24: traces and error reports, sent to whatever the operator runs. Both use open protocols, so no vendor is built in:
 *  - traces: OTLP/HTTP (JSON) — accepted by an OpenTelemetry Collector, Grafana Tempo, Honeycomb, Datadog, New Relic,
 *    Elastic, Jaeger and others;
 *  - errors: the Sentry envelope protocol — accepted by Sentry, GlitchTip and Bugsink (all three can be self-hosted).
 * Each is off until its endpoint is set. Every span and every error carries the firm (`tenant`) and request id.
 */
return [
    'tracing' => [
        // e.g. http://otel-collector:4318 (the /v1/traces path is added)
        'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT'),
        // "key1=value1,key2=value2", as in the OpenTelemetry SDKs (e.g. an API key header)
        'headers' => env('OTEL_EXPORTER_OTLP_HEADERS', ''),
        'service_name' => env('OTEL_SERVICE_NAME', 'lawfirm'),
        // share of traces kept (0..1); a trace whose caller already sampled it (traceparent flag 01) is always kept
        'sample_ratio' => (float) env('OTEL_TRACES_SAMPLER_ARG', 1.0),
        // database statements become child spans; their text is kept without bound values
        'database_spans' => (bool) env('OTEL_DATABASE_SPANS', true),
        'max_spans' => (int) env('OTEL_MAX_SPANS', 500),
        'timeout' => (float) env('OTEL_EXPORTER_OTLP_TIMEOUT', 2.0),
    ],

    'errors' => [
        // https://<public key>@<host>/<project id>
        'dsn' => env('ERROR_TRACKING_DSN', env('SENTRY_DSN')),
        'environment' => env('ERROR_TRACKING_ENVIRONMENT', env('APP_ENV', 'production')),
        'release' => env('ERROR_TRACKING_RELEASE', env('APP_VERSION')),
        // group the same error separately per firm (one issue per firm); off = one issue, filter by the tenant tag
        'group_per_tenant' => (bool) env('ERROR_TRACKING_GROUP_PER_TENANT', true),
        'timeout' => (float) env('ERROR_TRACKING_TIMEOUT', 2.0),
    ],
];

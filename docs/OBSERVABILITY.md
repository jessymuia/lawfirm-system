# Observability baseline (ENG-24)

## Built and tested
| Need | What exists |
|---|---|
| Request correlation | `RequestContext` middleware: `X-Request-Id` (honoured only if 8-64 chars of `[A-Za-z0-9._-]`, else replaced by a UUID) echoed on every response |
| Structured logs | Channels `json` (daily file) and `stderr_json` (containers). One JSON object per line; `extra` carries `request_id`, `tenant`, `user_id` |
| Correlation in jobs | Laravel `Context` rides in the queue payload, so a job's log lines keep the originating `request_id` and `tenant` |
| Errors per tenant | `Handler::context()` adds `tenant` and `request_id` to every reported exception; any tracker reading Laravel's exception context groups/filters by them |
| Usage: API calls | `CountApiCall` (terminable, after the response) increments `tenant_usage_daily` (control DB) |
| Usage: users, matters, clients, invoices, documents, storage | `php artisan tenants:usage` (scheduled 01:30 daily) measures inside each tenant DB and stores the day's gauges; one broken tenant does not stop the run |

Enable JSON logs with `LOG_CHANNEL=json` (or `stderr_json`). Usage data is in the control database:
`SELECT t.slug, u.day, u.metric, u.value FROM tenant_usage_daily u JOIN tenants t ON t.id = u.tenant_id`.
It is also the input PLAT-4 (billing/metering) will need.

## Traces (APM) — OTLP, any backend
Off until `OTEL_EXPORTER_OTLP_ENDPOINT` is set (e.g. `http://otel-collector:4318`; `/v1/traces` is appended). The app
speaks OTLP/HTTP JSON itself (no agent or extension), so any OpenTelemetry backend works: an OpenTelemetry Collector,
Grafana Tempo, Jaeger, Honeycomb, Datadog, New Relic, Elastic. Choosing a vendor is configuration, not code.

| What | How |
|---|---|
| One trace per request | `TraceRequests` (global, after `RequestContext`): root span `GET /api/v1/cases/{case}` (route template, never the URL with ids), status code, method, host |
| Firm on every trace | root span attributes `tenant`, `request_id`, `enduser.id` |
| Database | each statement is a child span (`db.query.text` as prepared, **bound values never leave**); `OTEL_DATABASE_SPANS=false` turns them off; `OTEL_MAX_SPANS` (500) caps a trace |
| Jobs | each queued job is its own trace continuing the request that dispatched it (W3C `traceparent` rides in hidden Context); a job run inline (`sync`) is a child span |
| Callers | an inbound `traceparent` is continued; a caller that sampled is always kept |
| Sampling | `OTEL_TRACES_SAMPLER_ARG` (0..1, default 1) |
| Auth to the backend | `OTEL_EXPORTER_OTLP_HEADERS="x-honeycomb-team=KEY"` (comma-separated, URL-encoded values) |
| Cost of a dead collector | one POST after the response with `OTEL_EXPORTER_OTLP_TIMEOUT` (2 s); failures logged at debug, never shown to users |

## Error tracking — Sentry protocol, any compatible tracker
Off until `ERROR_TRACKING_DSN` (or `SENTRY_DSN`) is set. Events go over the Sentry envelope protocol, accepted by
Sentry (SaaS or self-hosted), GlitchTip and Bugsink, so the tracker can run in the same region as the data.

| What | How |
|---|---|
| Per-firm grouping | fingerprint `{{ default }} + tenant:<firm>`: the same bug in three firms is three issues, each with its own count. `ERROR_TRACKING_GROUP_PER_TENANT=false` makes it one issue, filterable by the `tenant` tag |
| Tags | `tenant`, `request_id`, `route` (template), `http_method`; `release` (`ERROR_TRACKING_RELEASE`, default the app version), `environment` |
| Link to the trace | `contexts.trace.trace_id` = the request's trace id |
| What is sent | exception class, message, chained causes, stack frames (file, line, function) |
| What is never sent | request bodies, headers, cookies, query strings, function arguments, local variables |
| Not reported | the exception types the handler does not report (entitlement denials, legal-hold refusals, etc.) |

Messages can contain data (e.g. a database error quoting a value), so treat the tracker as holding client data:
host it where the firm data may live, restrict access, and set its retention.

## Not built
- Metrics export (OTLP metrics) and dashboards/alerts on the usage table.
- Browser (web app) error and performance reporting.

## Notes
- API-call counting is one control-DB upsert per API request, after the response. At high volume, switch to
  buffering in Redis and flushing per minute (ENG-20).
- Counting only happens in multi-tenant mode; single-tenant installs can still run `tenants:usage` to print gauges.

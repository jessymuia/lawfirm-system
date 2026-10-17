# Queues, Redis and fair share between firms (ENG-20)

## Lanes

Background work is split into two lanes, so heavy work never sits in front of work someone is waiting for.

| Lane | What runs there |
|---|---|
| `default` (or a cell's own lane, e.g. `eu`; TEN-2) | Interactive work people wait on: SMS and mail, calendar sync, webhooks, signup provisioning. |
| `bulk` (or `<lane>-bulk`, e.g. `eu-bulk`) | Heavy work: firm-wide exports, reading and OCR of documents, backfills after an upgrade. |

A job goes to a bulk lane when it uses the `App\Queue\BulkWork` trait (`App\Queue\Lanes`).

The default worker serves both lanes, interactive work first: `queue:work --queue=default,bulk` (in Docker,
`QUEUE_WORKER_QUEUES`). As load grows:

- **Scale out:** run more workers. Any number may take jobs from the same queue, on any number of hosts.
- **Separate heavy work:** give bulk its own workers. In Helm, enable `bulkQueue.enabled` and set `queue.lanes=default`.
  In Docker, run one container with `QUEUE_WORKER_QUEUES=default` and another with `QUEUE_WORKER_QUEUES=bulk`.
- **Cells:** a cell with its own lane needs workers on `<lane>,<lane>-bulk`.

## Fair share

Each firm runs at most `QUEUE_BULK_PER_FIRM` bulk jobs at once (default 2), however many it queued and however many
workers there are.

- **When the slots are taken:** a job is put back for `QUEUE_BULK_RETRY_AFTER` seconds (default 30), and the worker
  moves on to other firms' work. One firm exporting everything cannot starve its cell.
- **Retries:** waiting for a slot does not count as a failure. A bulk job keeps trying for `QUEUE_BULK_RETRY_HOURS`
  (default 24). Real errors are limited by the job's `$maxExceptions`.
- **How slots work:** they are cache locks under the firm's cache prefix (TEN-6). With Redis they hold across every
  worker host. The database and file cache stores work too, but only for workers that share them.

## Redis

Redis is recommended once there is more than one web or worker host. It carries cache, sessions, the queue and the
bulk slots.

```
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_HOST=redis.internal
REDIS_PASSWORD=...          # or REDIS_PASSWORD_FILE
```

- **Helm:** set `redis.host`, plus `secrets.redisPassword` or `REDIS_PASSWORD` in your existing Secret.
- **Image:** the production image includes the `redis` PHP extension.
- **Firm isolation:**
  - **Cache:** cache keys carry the firm prefix. `CacheNamespacingRulesTest` forbids code that writes raw Redis keys.
  - **Sessions:** cookies are per firm (TEN-10), so firms' sessions stay apart.
  - **Queue:** queued jobs carry their firm and run inside it.
  - **Tested against a real Redis:** `RedisIntegrationTest`.
- **Existing jobs:** switching `QUEUE_CONNECTION` does not move jobs already waiting. Drain the old queue first
  (`php artisan queue:work --stop-when-empty`).

## Timeouts

`QUEUE_RETRY_AFTER` (default 3800 s) must be longer than the longest job. An export may run for an hour, and a shorter
value hands a running job to a second worker. Only a job whose worker died waits that long; a failing job is released
at once. Docker workers use `--timeout=${QUEUE_WORKER_TIMEOUT:-3700}`.

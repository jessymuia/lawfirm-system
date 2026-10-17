<?php

namespace App\Observability;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;

/** ENG-24: database statements as child spans, and one trace per queued job. Nothing is registered with tracing off. */
final class Instrumentation
{
    public static function register(): void
    {
        $tracer = app(Tracer::class);
        if (! $tracer->enabled()) {
            return;
        }

        if (config('observability.tracing.database_spans', true)) {
            Event::listen(function (QueryExecuted $q) use ($tracer) {
                if (! $tracer->root()) {
                    return;
                }
                $end = Tracer::now();
                $op = strtoupper((string) strtok(ltrim($q->sql), " \n\t("));
                // the statement as prepared: bound values (client data) never leave
                $tracer->child("db {$op}", 3, [
                    'db.system' => $q->connection->getDriverName(),
                    'db.namespace' => $q->connection->getDatabaseName(),
                    'db.operation.name' => $op,
                    'db.query.text' => mb_substr($q->sql, 0, 2000),
                ], $end - (int) ($q->time * 1e6))?->end($end);
            });
        }

        Event::listen(fn (JobProcessing $e) => $tracer->startJob($e->job->resolveName(), [
            'messaging.system' => $e->connectionName,
            'messaging.destination.name' => $e->job->getQueue(),
            'job.attempt' => $e->job->attempts(),
        ]));
        Event::listen(fn (JobProcessed $e) => $tracer->endJob());
        Event::listen(fn (JobExceptionOccurred $e) => $tracer->endJob(get_class($e->exception).': '.$e->exception->getMessage()));
    }
}

<?php

namespace App\Console\Commands;

use App\Status\Incidents;
use App\Status\StatusException;
use Illuminate\Console\Command;

/** PLAT-12: post to the status page from the command line (the only way on a self-hosted install, which has no platform console). */
class StatusIncident extends Command
{
    protected $signature = 'status:incident {action : open|update} {message : text shown publicly}
        {--title= : (open) headline} {--impact=minor : (open) none|minor|major|maintenance}
        {--components=* : component ids; none = everything} {--status= : investigating|identified|monitoring|resolved|scheduled}
        {--id= : (update) incident id} {--starts= : (maintenance) start time} {--ends= : (maintenance) end time}';

    protected $description = 'Open or update a status-page incident or planned maintenance';

    public function handle(Incidents $incidents): int
    {
        try {
            if ($this->argument('action') === 'open') {
                $i = $incidents->open(array_filter([
                    'title' => (string) $this->option('title'), 'impact' => $this->option('impact'), 'message' => $this->argument('message'),
                    'components' => $this->option('components'), 'status' => $this->option('status'),
                    'starts_at' => $this->option('starts'), 'ends_at' => $this->option('ends'),
                ], fn ($v) => $v !== null && $v !== '' && $v !== []));
            } elseif ($this->argument('action') === 'update') {
                $i = $incidents->update((int) $this->option('id'), (string) $this->option('status'), $this->argument('message'));
            } else {
                $this->error('Action must be open or update.');

                return self::INVALID;
            }
        } catch (StatusException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("Incident #{$i->id}: {$i->status}");

        return self::SUCCESS;
    }
}

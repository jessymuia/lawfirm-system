<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class TestSMS extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:sms';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Testing sms';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        sendCustomSMS('0717941258', 'Testing');

        return Command::SUCCESS;
    }
}

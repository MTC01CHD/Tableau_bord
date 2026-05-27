<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('hfsql:sync')
    ->cron(config('hfsql.sync.cron', '*/15 * * * *'))
    ->withoutOverlapping(10)
    ->runInBackground()
    ->onOneServer();

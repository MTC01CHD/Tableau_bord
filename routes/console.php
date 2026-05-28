<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sync automatique horaire : itère tous les tenants actifs.
// Indispensable pour que les données restent fraîches sans intervention humaine
// (le sync continue de tourner serveur même quand personne n'est connecté).
Schedule::command('hfsql:sync --all-tenants')
    ->cron(config('hfsql.sync.cron', '0 * * * *'))
    ->withoutOverlapping(60)   // évite qu'un sync lent en chevauche un autre
    ->runInBackground()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/hfsql-sync-cron.log'));

// Nettoyage des jobs trop vieux dans la queue (sécurité : si un job s'est planté
// sans retry, ne le garde pas indéfiniment)
Schedule::command('queue:prune-failed --hours=48')->daily();

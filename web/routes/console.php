<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// сроки хранения журнала действий (config/audit.php) и документов (config/documents.php)
Schedule::command('model:prune')->daily();

// брошенные временные загрузки — копии документов с персональными данными
Schedule::command('app:prune-temporary-uploads')->hourly();

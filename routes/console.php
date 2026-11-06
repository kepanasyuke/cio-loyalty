<?php

declare(strict_types=1);

use App\Console\Commands\ExpirePoints;
use App\Jobs\RecalculateTier;
use Illuminate\Support\Facades\Schedule;

Schedule::command(ExpirePoints::class)
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new RecalculateTier)
    ->weekly()
    ->sundays()
    ->at('04:30');

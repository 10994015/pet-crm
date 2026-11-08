<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('babygo:backup')->dailyAt('03:00')->withoutOverlapping();

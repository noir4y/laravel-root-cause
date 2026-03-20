<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment('Laravel Root Cause demo ready.');
})->purpose('Display a demo message');

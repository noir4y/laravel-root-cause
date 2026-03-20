<?php

use App\Http\Controllers\IncidentController;
use App\Http\Controllers\ValidationIncidentController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::prefix('demo')->group(function () {
    Route::match(['GET', 'POST'], '/validation-failure', [ValidationIncidentController::class, 'store'])
        ->name('demo.validation-failure');

    Route::get('/unhandled-exception', [IncidentController::class, 'explode'])
        ->name('demo.unhandled-exception');

    Route::get('/duplicate-query-burst', function () {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            DB::select('select name from sqlite_master where type = ?', ['table']);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Repeated identical query fingerprint emitted five times.',
        ]);
    })->name('demo.duplicate-query-burst');

    Route::get('/n-plus-one', [IncidentController::class, 'nPlusOne'])
        ->name('demo.n-plus-one');
});

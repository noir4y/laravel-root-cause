<?php

namespace LaravelRootCause\Tests\Fixtures\Models;

use Illuminate\Support\Facades\DB;

class NPlusOneProbe
{
    public function runQueries(): void
    {
        DB::select('select name from sqlite_master');
        DB::select('select name from sqlite_master');
        DB::select('select name from sqlite_master');
    }
}

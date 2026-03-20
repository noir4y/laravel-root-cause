<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class IncidentController extends Controller
{
    public function explode(): JsonResponse
    {
        DB::select('select 1');

        throw new \RuntimeException('Demo unhandled exception from the controller.');
    }

    public function nPlusOne(): JsonResponse
    {
        $posts = Post::query()->orderBy('id')->get();
        $commentCounts = [];

        foreach ($posts as $post) {
            $commentCounts[$post->id] = $post->comments()->count();
        }

        return response()->json([
            'ok' => true,
            'posts' => $posts->count(),
            'comment_counts' => $commentCounts,
            'message' => 'Repeated relationship lookups are intentionally uncached here.',
        ]);
    }
}

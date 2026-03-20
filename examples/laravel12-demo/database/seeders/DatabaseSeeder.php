<?php

namespace Database\Seeders;

use App\Models\Comment;
use App\Models\Post;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Comment::query()->delete();
        Post::query()->delete();

        for ($index = 1; $index <= 3; $index++) {
            $post = Post::create([
                'title' => "Demo post {$index}",
            ]);

            for ($commentIndex = 1; $commentIndex <= 2; $commentIndex++) {
                Comment::create([
                    'post_id' => $post->id,
                    'body' => "Comment {$commentIndex} for post {$index}",
                ]);
            }
        }
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Request;

class PostController extends Controller
{
    //
    public function createPost(Request $request)
    {
        $fields = array_merge(
            ['body' => null, 'poster' => null],
            $request->validate([
                'title' => 'required|string|max:255',
                'body' => 'sometimes|string|max:3000',
                'poster' => 'sometimes|string|max:255',
            ])
        );

        $post = Post::create([
            'user_id' => $request->user()->id,
            ...$fields,
        ]);

        return response()->json([
            'message' => 'Post created.',
            'post' => $post
        ], 201);
    }
}

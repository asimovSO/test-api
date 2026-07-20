<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Request;

class PostController extends Controller
{
    //
    public function createPost(Request $request){
        $fields = $request->only([
            'title', 'body', 'poster'
        ]);

        $post = Post::create($fields);

        return response()->json([
            'message' => 'Post created.',
            'post' => $post
        ], 201);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PostController extends Controller
{

    public function showAllPosts()
    {
        $posts = Post::all();

        return response()->json($posts);
    }

    public function createPost(Request $request)
    {
        $fields = array_merge(
            ['body' => null, 'poster' => null],
            $request->validate([
                'title' => 'required|string|max:255',
                'body' => 'sometimes|string|max:3000',
                'poster' => 'sometimes|image|mimes:jpg,jpeg,png,webp',
            ])
        );

        if ($request->hasFile('poster')) {
            $fields['poster'] = $request->file('poster')->store('posters', 'public');
        }

        // $post = Post::create([
        //     'user_id' => $request->user()->id,
        //     ...$fields,
        // ]);

        $post = $request->user()->posts()->create($fields);

        return response()->json([
            'message' => 'Post created.',
            'post' => $post
        ], 201);
    }

    public function updatePost(Request $request, int $id)
    {
        $post = Post::findOrFail($id);
        $user = $request->user();

        if ($user->id !== $post->user_id) {
            return response()->json([
                'message' => 'Forbidden'
            ], 403);
        }

        $fields = $request->validate([
            'title' => 'sometimes|string|max:255',
            'body' => 'sometimes|string|max:3000',
            'poster' => 'sometimes|image',
        ]);

        if ($request->hasFile('poster')) {
            if ($post->poster) {
                Storage::disk('public')->delete($post->poster);
            }
            $fields['poster'] = $request->file('poster')->store('posters', 'public');
        }

        $post->update($fields);

        return response()->json($post);
    }

    public function deletePost(Request $request, int $id)
    {
        $post = Post::findOrFail($id);
        $user = $request->user();

        if ($user->id !== $post->user_id) {
            return response()->json([
                'message' => 'Forbidden'
            ], 403);
        }

        $post->delete();

        return response()->noContent();
    }
}

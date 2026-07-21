<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PostController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/register', [AuthController::class, 'createNewUser']);
Route::post('/login', [AuthController::class, 'loginUser']);


Route::middleware('auth:sanctum')->group(function(){
    Route::get('/posts', [PostController::class, 'showAllPosts']);
    Route::post('/posts', [PostController::class, 'createPost']);
    Route::put('/posts/{id}', [PostController::class, 'updatePost']);
    Route::delete('/posts/{id}', [PostController::class, 'deletePost']);
});

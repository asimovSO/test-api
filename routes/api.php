<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PostController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::controller(AuthController::class)->group(function(){
    Route::post('/register', 'createNewUser');
    Route::post('/login', 'loginUser');
    Route::post('/logout', 'logoutUser')->middleware('auth:sanctum');
});

Route::middleware('auth:sanctum')->group(function(){
    Route::get('/posts', [PostController::class, 'showAllPosts']);
    Route::post('/posts', [PostController::class, 'createPost']);
    Route::put('/posts/{id}', [PostController::class, 'updatePost']);
    Route::delete('/posts/{id}', [PostController::class, 'deletePost']);
});

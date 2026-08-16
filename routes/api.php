<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\MessageController;
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

    Route::prefix('/conversations')->group(function(){
        Route::post('/group', [ConversationController::class, 'createGroupConversation']);
        Route::get('/', [ConversationController::class, 'getAllUserConversations']);
        Route::post('/{user}', [ConversationController::class, 'createConversation']); // для созданя беседы с пользователем
        Route::delete('/{conversation}', [ConversationController::class, 'deleteConversation']);
        Route::put('/{conversation}/mark-as-read', [ConversationController::class, 'markAsRead']);
        Route::get('/{conversation}/messages', [MessageController::class, 'getMessages']);
        Route::post('/{conversation}/messages', [MessageController::class, 'sendMessage']);
        Route::post('/{conversation}/participant/{user}', [ConversationController::class, 'addParticipant']);
        Route::delete('/{conversation}/participant/{user}', [ConversationController::class, 'removeParticipant']);
        Route::post('/{conversation}/quit', [ConversationController::class, 'quitConversation']);
    });

    Route::prefix('/messages')->group(function(){
        Route::put('/{message}', [MessageController::class, 'updateMessage']);
        Route::delete('/{message}', [MessageController::class, 'deleteMessage']);
    });

});

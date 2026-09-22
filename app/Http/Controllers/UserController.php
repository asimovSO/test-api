<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Http\Resources\UserResource;

class UserController extends Controller
{
    //
    public function getAllUsers()
    {
        $users = User::all();
        return UserResource::collection($users);
    }

    public function getUserById(User $user)
    {
        return new UserResource($user);
    }

    public function searchUsers(Request $request)
    {
        $users = User::where('name', 'like', "%{$request->input('query')}%")->orWhere('email', 'like', "%{$request->input('query')}%")->get();
        return UserResource::collection($users);
    }
}

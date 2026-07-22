<?php

namespace App\Http\Controllers;

use App\Mail\WelcomeMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

class AuthController extends Controller
{
    //
    public function createNewUser(Request $request)
    {
        $fields = $request->validate([
            'name' => 'required|min:3|max:230|string',
            'email' => 'email|unique:users|required',
            'password' => 'min:3|required',
        ]);

        $user = User::create($fields);

        $token = $user->createToken('api-token')->plainTextToken;

        Mail::to($user->email)->send(new WelcomeMail($user));

        return response()->json([
            'token' => $token,
            'user' => $user,
        ]);
    }

    public function loginUser(Request $request)
    {
        $fields = $request->validate([
            'email' => 'email|required',
            'password' => 'string|required',
        ]);

        if (! Auth::attempt($fields)) {
            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        Auth::user()->tokens()->delete();

        $token = Auth::user()->createToken('api-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => Auth::user(),
        ]);
    }
}

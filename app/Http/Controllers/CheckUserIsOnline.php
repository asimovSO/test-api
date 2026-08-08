<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Resources\UserResource;
use App\Services\PresenceService;
class CheckUserIsOnline extends Controller
{
    //
    public function __invoke(Request $request)
    {
        $user = $request->user();
        PresenceService::heartbeat($user->id);

        return response()->json([
            'message' => 'User is online',
            'user' => new UserResource($user),
        ], 200);
    }
}

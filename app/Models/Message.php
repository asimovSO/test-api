<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use App\Models\Conversation;

class Message extends Model
{
    //

    public function author(){
        return $this->belongsTo(User::class);
    }

    public function conversation(){
        return $this->belongTo(Conversation::class);
    }
}

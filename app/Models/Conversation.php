<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class Conversation extends Model
{
    //
    public function users(){
        return $this->hasMany(User::class);
    }
}

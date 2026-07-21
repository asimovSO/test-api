<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $fillable = ['title', 'body', 'poster', 'user_id'];

    public function user(){
        $this->belongsTo(User::class);
    }
}

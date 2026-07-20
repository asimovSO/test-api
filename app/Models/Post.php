<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $fillable = ['title', 'body', 'poster'];

    public function user(){
        $this->belongsTo(User::class);
    }
}

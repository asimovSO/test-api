<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = ['body', 'user_id', 'conversation_id'];
    protected $appends = ['is_edited'];

    protected $touches = ['conversation'];

    protected function getIsEditedAttribute(): bool
    {
        return $this->created_at != $this->updated_at;
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }
}

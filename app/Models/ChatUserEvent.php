<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatUserEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'conversation_id',
        'message_id',
        'call_session_id',
        'event_type',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

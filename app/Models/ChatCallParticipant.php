<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatCallParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'call_session_id',
        'user_id',
        'state',
        'acted_at',
    ];

    protected $casts = [
        'acted_at' => 'datetime',
    ];

    public function callSession()
    {
        return $this->belongsTo(ChatCallSession::class, 'call_session_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

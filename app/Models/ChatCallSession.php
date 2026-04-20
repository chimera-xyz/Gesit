<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatCallSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'initiated_by',
        'answered_by',
        'type',
        'status',
        'started_at',
        'ended_at',
        'last_activity_at',
        'metadata',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function conversation()
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function initiator()
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function answerer()
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    public function participants()
    {
        return $this->hasMany(ChatCallParticipant::class, 'call_session_id');
    }
}

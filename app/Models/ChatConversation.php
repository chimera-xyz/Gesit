<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatConversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'slug',
        'direct_hash',
        'title',
        'subtitle',
        'description',
        'accent_color',
        'created_by',
        'last_message_at',
    ];

    protected $casts = [
        'accent_color' => 'integer',
        'last_message_at' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function participants()
    {
        return $this->hasMany(ChatConversationParticipant::class, 'conversation_id');
    }

    public function states()
    {
        return $this->hasMany(ChatConversationUserState::class, 'conversation_id');
    }

    public function messages()
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }

    public function callSessions()
    {
        return $this->hasMany(ChatCallSession::class, 'conversation_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatConversationUserState extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'user_id',
        'last_read_message_id',
        'last_read_at',
        'is_pinned',
        'is_muted',
    ];

    protected $casts = [
        'last_read_message_id' => 'integer',
        'last_read_at' => 'datetime',
        'is_pinned' => 'boolean',
        'is_muted' => 'boolean',
    ];

    public function conversation()
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

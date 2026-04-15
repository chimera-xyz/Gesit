<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KnowledgeConversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function messages()
    {
        return $this->hasMany(KnowledgeConversationMessage::class)->orderBy('id');
    }

    public function latestMessage()
    {
        return $this->hasOne(KnowledgeConversationMessage::class)->latestOfMany();
    }
}

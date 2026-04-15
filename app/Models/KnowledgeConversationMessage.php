<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KnowledgeConversationMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'knowledge_conversation_id',
        'role',
        'content',
        'scope',
        'provider',
        'sources',
    ];

    protected function casts(): array
    {
        return [
            'sources' => 'array',
        ];
    }

    public function conversation()
    {
        return $this->belongsTo(KnowledgeConversation::class, 'knowledge_conversation_id');
    }
}

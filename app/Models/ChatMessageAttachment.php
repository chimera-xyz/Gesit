<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatMessageAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'message_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    public function message()
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }
}

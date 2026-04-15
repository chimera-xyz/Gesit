<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KnowledgeBookmark extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'knowledge_entry_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function entry()
    {
        return $this->belongsTo(KnowledgeEntry::class, 'knowledge_entry_id');
    }
}

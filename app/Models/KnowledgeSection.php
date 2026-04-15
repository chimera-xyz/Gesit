<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KnowledgeSection extends Model
{
    use HasFactory;

    protected $fillable = [
        'knowledge_space_id',
        'name',
        'description',
        'sort_order',
        'is_active',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function space()
    {
        return $this->belongsTo(KnowledgeSpace::class, 'knowledge_space_id');
    }

    public function entries()
    {
        return $this->hasMany(KnowledgeEntry::class)->orderBy('sort_order')->orderByDesc('updated_at');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class KnowledgeSpace extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'kind',
        'description',
        'ai_instruction',
        'knowledge_text',
        'icon',
        'sort_order',
        'is_active',
        'show_in_hub',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'show_in_hub' => 'boolean',
        ];
    }

    public function sections()
    {
        return $this->hasMany(KnowledgeSection::class)->orderBy('sort_order')->orderBy('name');
    }

    public function defaultSection()
    {
        return $this->hasOne(KnowledgeSection::class)->where('is_default', true);
    }

    public function documents(): HasManyThrough
    {
        return $this->hasManyThrough(
            KnowledgeEntry::class,
            KnowledgeSection::class,
            'knowledge_space_id',
            'knowledge_section_id'
        )->orderBy('sort_order')->orderByDesc('updated_at');
    }

    public function ensureDefaultSection(): KnowledgeSection
    {
        $section = $this->defaultSection()->first();

        if ($section) {
            return $section;
        }

        $this->sections()->update(['is_default' => false]);

        return $this->sections()->create([
            'name' => 'Knowledge Base',
            'description' => $this->kind === 'general'
                ? 'Section internal untuk knowledge umum AI.'
                : 'Section internal default untuk dokumen knowledge divisi.',
            'sort_order' => 0,
            'is_active' => true,
            'is_default' => true,
        ]);
    }
}

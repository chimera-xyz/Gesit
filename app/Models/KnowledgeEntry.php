<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

class KnowledgeEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'knowledge_section_id',
        'title',
        'summary',
        'body',
        'scope',
        'type',
        'source_kind',
        'owner_name',
        'reviewer_name',
        'version_label',
        'effective_date',
        'reference_notes',
        'source_link',
        'tags',
        'access_mode',
        'attachment_path',
        'attachment_name',
        'attachment_mime',
        'attachment_size',
        'attachment_text',
        'sort_order',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'effective_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (KnowledgeEntry $entry) {
            if ($entry->attachment_path) {
                Storage::disk('public')->delete($entry->attachment_path);
            }
        });
    }

    public function section()
    {
        return $this->belongsTo(KnowledgeSection::class, 'knowledge_section_id');
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'knowledge_entry_role')
            ->withTimestamps()
            ->orderBy('name');
    }

    public function bookmarks()
    {
        return $this->hasMany(KnowledgeBookmark::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $user->loadMissing('roles');
        $roleIds = $user->roles->pluck('id')->all();

        return $query
            ->where('is_active', true)
            ->whereHas('section', function (Builder $sectionQuery) {
                $sectionQuery->where('is_active', true)
                    ->whereHas('space', fn (Builder $spaceQuery) => $spaceQuery->where('is_active', true));
            })
            ->where(function (Builder $accessQuery) use ($roleIds) {
                $accessQuery->where('access_mode', 'all');

                if ($roleIds !== []) {
                    $accessQuery->orWhereHas('roles', fn (Builder $roleQuery) => $roleQuery->whereIn('roles.id', $roleIds));
                }
            });
    }
}

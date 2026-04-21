<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class FeedPost extends Model
{
    use HasFactory;

    public const VISIBILITY_PUBLIC = 'public';
    public const VISIBILITY_DEPARTMENT = 'department';
    public const VISIBILITY_PRIVATE = 'private';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'visibility',
        'target_department',
        'content',
        'like_count',
        'comment_count',
        'last_activity_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'like_count' => 'integer',
        'comment_count' => 'integer',
        'last_activity_at' => 'datetime',
    ];

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(FeedComment::class, 'post_id')->orderBy('created_at');
    }

    public function rootComments(): HasMany
    {
        return $this->comments()->roots();
    }

    public function likes(): MorphMany
    {
        return $this->morphMany(FeedLike::class, 'likeable');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $visibleQuery) use ($user) {
            $visibleQuery
                ->where('visibility', self::VISIBILITY_PUBLIC)
                ->orWhere('user_id', $user->id);

            $department = trim((string) ($user->department ?? ''));
            if ($department !== '') {
                $visibleQuery->orWhere(function (Builder $departmentQuery) use ($department) {
                    $departmentQuery
                        ->where('visibility', self::VISIBILITY_DEPARTMENT)
                        ->where('target_department', $department);
                });
            }
        });
    }
}

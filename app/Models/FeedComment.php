<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class FeedComment extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'post_id',
        'user_id',
        'parent_id',
        'reply_to_comment_id',
        'reply_to_user_id',
        'content',
        'like_count',
        'reply_count',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'like_count' => 'integer',
        'reply_count' => 'integer',
    ];

    public function post()
    {
        return $this->belongsTo(FeedPost::class, 'post_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replyToComment()
    {
        return $this->belongsTo(self::class, 'reply_to_comment_id');
    }

    public function replyToUser()
    {
        return $this->belongsTo(User::class, 'reply_to_user_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('created_at');
    }

    public function likes(): MorphMany
    {
        return $this->morphMany(FeedLike::class, 'likeable');
    }

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }
}

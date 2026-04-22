<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class MobileAppRelease extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform',
        'channel',
        'version_name',
        'version_code',
        'minimum_supported_version_code',
        'release_notes',
        'apk_path',
        'apk_file_name',
        'apk_mime_type',
        'file_size',
        'sha256',
        'is_published',
        'published_at',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'version_code' => 'integer',
            'minimum_supported_version_code' => 'integer',
            'file_size' => 'integer',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }
}

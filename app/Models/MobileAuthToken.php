<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MobileAuthToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'device_id',
        'device_name',
        'platform',
        'token_hash',
        'last_used_at',
        'expires_at',
        'revoked_at',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

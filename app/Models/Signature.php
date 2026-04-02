<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Signature extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'signature_image',
        'signature_type',
        'signature_hash',
        'metadata',
        'verified',
        'signed_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected $casts = [
        'metadata' => 'array',
        'verified' => 'boolean',
        'signed_at' => 'datetime',
    ];

    /**
     * Get the user who created this signature.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the approval step associated with this signature.
     */
    public function approvalStep()
    {
        return $this->hasOne(ApprovalStep::class, 'signature_id');
    }

    /**
     * Generate a hash for signature verification.
     */
    public function generateHash()
    {
        $data = [
            'user_id' => $this->user_id,
            'approval_step_id' => $this->metadata['approval_step_id'] ?? null,
            'signature_type' => $this->signature_type,
            'signature_image' => $this->signature_image,
            'timestamp' => now()->toDateTimeString(),
        ];

        $this->signature_hash = hash('sha256', json_encode($data));
        $this->save();

        return $this->signature_hash;
    }

    /**
     * Verify the signature hash.
     */
    public function verifyHash($hash)
    {
        return $this->signature_hash === $hash;
    }
}

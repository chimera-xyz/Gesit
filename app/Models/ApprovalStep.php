<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ApprovalStep extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'form_submission_id',
        'step_number',
        'step_key',
        'step_name',
        'approver_role',
        'actor_type',
        'actor_value',
        'actor_label',
        'approver_id',
        'status',
        'notes',
        'signature_id',
        'config_snapshot',
        'approved_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected $casts = [
        'approved_at' => 'datetime',
        'config_snapshot' => 'array',
    ];

    /**
     * Get the form submission associated with this approval step.
     */
    public function formSubmission()
    {
        return $this->belongsTo(FormSubmission::class, 'form_submission_id');
    }

    /**
     * Get the approver.
     */
    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    /**
     * Get the signature for this approval step.
     */
    public function signature()
    {
        return $this->belongsTo(Signature::class, 'signature_id');
    }
}

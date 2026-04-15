<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FormSubmission extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'form_id',
        'user_id',
        'form_data',
        'form_snapshot',
        'workflow_snapshot',
        'current_status',
        'current_step',
        'pdf_path',
        'rejection_reason',
        'created_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected $casts = [
        'form_data' => 'array',
        'form_snapshot' => 'array',
        'workflow_snapshot' => 'array',
    ];

    /**
     * Get the form associated with this submission.
     */
    public function form()
    {
        return $this->belongsTo(Form::class);
    }

    /**
     * Get the user who submitted this form.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the approval steps for this submission.
     */
    public function approvalSteps()
    {
        return $this->hasMany(ApprovalStep::class, 'form_submission_id');
    }

    /**
     * Get the creator of this submission.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Resolve the field schema captured when the submission was created.
     */
    public function resolvedFormConfig(): array
    {
        if (is_array($this->form_snapshot) && $this->form_snapshot !== []) {
            return $this->form_snapshot;
        }

        return is_array($this->form?->form_config) ? $this->form->form_config : [];
    }

    /**
     * Resolve the workflow schema captured when the submission was created.
     */
    public function resolvedWorkflowConfig(): array
    {
        if (is_array($this->workflow_snapshot) && $this->workflow_snapshot !== []) {
            return $this->workflow_snapshot;
        }

        return is_array($this->form?->workflow?->workflow_config) ? $this->form->workflow->workflow_config : [];
    }
}

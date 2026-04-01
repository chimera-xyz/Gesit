<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Form extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'form_config',
        'workflow_id',
        'is_active',
        'created_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected $casts = [
        'form_config' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Get the workflow associated with this form.
     */
    public function workflow()
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * Get the form submissions for this form.
     */
    public function submissions()
    {
        return $this->hasMany(FormSubmission::class, 'form_id');
    }

    /**
     * Get the creator of this form.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

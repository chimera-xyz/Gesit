<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Workflow extends Model
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
        'workflow_config',
        'is_active',
        'created_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected $casts = [
        'workflow_config' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Get the forms associated with this workflow.
     */
    public function forms()
    {
        return $this->hasMany(Form::class);
    }

    /**
     * Get the creator of this workflow.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

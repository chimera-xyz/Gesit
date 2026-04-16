<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class S21PlusUnblockAuditLog extends Model
{
    use HasFactory;

    protected $table = 's21plus_unblock_audit_logs';

    protected $fillable = [
        'gesit_user_id',
        'gesit_user_name',
        's21plus_user_id',
        'knowledge_conversation_id',
        'knowledge_conversation_message_id',
        'request_type',
        'before_is_enabled',
        'before_login_retry',
        'after_is_enabled',
        'after_login_retry',
        'status',
        'result_code',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'before_is_enabled' => 'boolean',
            'after_is_enabled' => 'boolean',
        ];
    }
}

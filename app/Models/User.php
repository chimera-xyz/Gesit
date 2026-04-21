<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'employee_id',
        's21plus_user_id',
        'department',
        'phone_number',
        'signature_path',
        'is_active',
    ];

    /**
     * Default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the forms created by the user.
     */
    public function createdForms()
    {
        return $this->hasMany(Form::class, 'created_by');
    }

    /**
     * Get the form submissions by the user.
     */
    public function formSubmissions()
    {
        return $this->hasMany(FormSubmission::class, 'user_id');
    }

    /**
     * Get the workflows created by the user.
     */
    public function createdWorkflows()
    {
        return $this->hasMany(Workflow::class, 'created_by');
    }

    /**
     * Get the approval steps where user is the approver.
     */
    public function approvalSteps()
    {
        return $this->hasMany(ApprovalStep::class, 'approver_id');
    }

    /**
     * Get signatures by the user.
     */
    public function signatures()
    {
        return $this->hasMany(Signature::class, 'user_id');
    }

    /**
     * Get notifications for the user.
     */
    public function notifications()
    {
        return $this->hasMany(Notification::class, 'user_id');
    }

    /**
     * Get helpdesk tickets created for this user.
     */
    public function helpdeskTickets()
    {
        return $this->hasMany(HelpdeskTicket::class, 'requester_id');
    }

    /**
     * Get helpdesk tickets currently assigned to this user.
     */
    public function assignedHelpdeskTickets()
    {
        return $this->hasMany(HelpdeskTicket::class, 'assigned_to');
    }

    /**
     * Get timeline updates authored by this user in helpdesk tickets.
     */
    public function helpdeskTicketUpdates()
    {
        return $this->hasMany(HelpdeskTicketUpdate::class);
    }

    /**
     * Get bookmarked knowledge entries for this user.
     */
    public function knowledgeBookmarks()
    {
        return $this->hasMany(KnowledgeBookmark::class);
    }

    /**
     * Get knowledge assistant conversations for this user.
     */
    public function knowledgeConversations()
    {
        return $this->hasMany(KnowledgeConversation::class);
    }

    public function chatConversationParticipants()
    {
        return $this->hasMany(ChatConversationParticipant::class);
    }

    public function chatConversationStates()
    {
        return $this->hasMany(ChatConversationUserState::class);
    }

    public function chatMessages()
    {
        return $this->hasMany(ChatMessage::class, 'sender_id');
    }

    public function chatCallSessions()
    {
        return $this->hasMany(ChatCallSession::class, 'initiated_by');
    }

    public function chatCallParticipations()
    {
        return $this->hasMany(ChatCallParticipant::class);
    }

    public function chatUserEvents()
    {
        return $this->hasMany(ChatUserEvent::class);
    }

    public function mobileAuthTokens()
    {
        return $this->hasMany(MobileAuthToken::class);
    }

    public function feedPosts()
    {
        return $this->hasMany(FeedPost::class);
    }

    public function feedComments()
    {
        return $this->hasMany(FeedComment::class);
    }

    public function feedLikes()
    {
        return $this->hasMany(FeedLike::class);
    }
}

<?php

namespace App\Support;

use App\Models\ApprovalStep;
use App\Models\FormSubmission;
use App\Models\HelpdeskTicketUpdate;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ItActivityService
{
    private const HELPDESK_CATEGORY_LABELS = [
        'hardware' => 'Mouse / Keyboard / Perangkat',
        'printer' => 'Printer / Scanner',
        'internet' => 'Internet / VPN',
        'email' => 'Email / Outlook',
        'account_access' => 'Akses Akun / Permission',
        'software' => 'Aplikasi / Software',
        'installation' => 'Instalasi Aplikasi',
        'other' => 'Lainnya',
    ];

    private const HELPDESK_CHANNEL_LABELS = [
        'portal' => 'Portal',
        'phone' => 'Panggilan',
    ];

    private const HELPDESK_STATUS_LABELS = [
        'open' => 'Open',
        'in_progress' => 'In Progress',
        'waiting_user' => 'Waiting User',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ];

    /**
     * @var array<int, User|null>
     */
    private array $userCache = [];

    public function paginate(array $filters, int $page = 1, int $perPage = 25): array
    {
        $normalizedFilters = $this->normalizeFilters($filters);
        $activities = $this->collectActivities($normalizedFilters);
        $total = $activities->count();
        $lastPage = max((int) ceil($total / max($perPage, 1)), 1);
        $currentPage = min(max($page, 1), $lastPage);

        return [
            'activities' => $activities
                ->forPage($currentPage, $perPage)
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $currentPage,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
            'stats' => $this->buildStats($activities),
            'filters' => $this->filterOptions(),
            'applied_filters' => $this->responseFilters($normalizedFilters),
            'filter_summary' => $this->describeFilters($normalizedFilters),
        ];
    }

    public function exportPayload(array $filters): array
    {
        $normalizedFilters = $this->normalizeFilters($filters);
        $activities = $this->collectActivities($normalizedFilters);

        return [
            'activities' => $activities->all(),
            'stats' => $this->buildStats($activities),
            'filters' => $this->responseFilters($normalizedFilters),
            'filter_summary' => $this->describeFilters($normalizedFilters),
            'generated_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function normalizeFilters(array $filters): array
    {
        $module = (string) ($filters['module'] ?? 'all');
        $dateFrom = ! empty($filters['date_from']) ? Carbon::parse((string) $filters['date_from'])->startOfDay() : null;
        $dateTo = ! empty($filters['date_to']) ? Carbon::parse((string) $filters['date_to'])->endOfDay() : null;

        return [
            'search' => trim((string) ($filters['search'] ?? '')),
            'module' => in_array($module, ['all', 'helpdesk', 'submission'], true) ? $module : 'all',
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function responseFilters(array $filters): array
    {
        return [
            'search' => $filters['search'],
            'module' => $filters['module'],
            'date_from' => $filters['date_from']?->toDateString(),
            'date_to' => $filters['date_to']?->toDateString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function collectActivities(array $filters): Collection
    {
        $activities = collect();

        if (($filters['module'] ?? 'all') !== 'submission') {
            $activities = $activities->merge($this->helpdeskActivities($filters));
        }

        if (($filters['module'] ?? 'all') !== 'helpdesk') {
            $activities = $activities->merge($this->submissionActivities($filters));
        }

        if (($filters['search'] ?? '') !== '') {
            $search = Str::lower((string) $filters['search']);

            $activities = $activities->filter(function (array $activity) use ($search) {
                $haystack = Str::lower(implode(' ', array_filter([
                    $activity['module_label'] ?? null,
                    $activity['activity_name'] ?? null,
                    $activity['reference_number'] ?? null,
                    $activity['item_title'] ?? null,
                    $activity['actor_name'] ?? null,
                    $activity['actor_role'] ?? null,
                    $activity['requester_name'] ?? null,
                    $activity['it_owner'] ?? null,
                    $activity['related_users'] ?? null,
                    $activity['status_at_event_label'] ?? null,
                    $activity['current_status_label'] ?? null,
                    $activity['summary'] ?? null,
                    $activity['notes'] ?? null,
                    $activity['context_label'] ?? null,
                    $activity['visibility_label'] ?? null,
                ])));

                return str_contains($haystack, $search);
            });
        }

        return $activities
            ->sort(function (array $left, array $right) {
                return [$right['sort_at'], $right['id']] <=> [$left['sort_at'], $left['id']];
            })
            ->values();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function helpdeskActivities(array $filters): Collection
    {
        $query = HelpdeskTicketUpdate::query()
            ->with([
                'ticket.requester.roles',
                'ticket.creator.roles',
                'ticket.assignee.roles',
                'user.roles',
            ]);

        if ($filters['date_from'] instanceof Carbon) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if ($filters['date_to'] instanceof Carbon) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        return $query
            ->get()
            ->map(fn (HelpdeskTicketUpdate $update) => $this->mapHelpdeskActivity($update))
            ->filter();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function submissionActivities(array $filters): Collection
    {
        $query = FormSubmission::query()->with([
            'form.workflow',
            'user.roles',
            'approvalSteps.approver.roles',
        ]);

        if ($filters['date_from'] instanceof Carbon || $filters['date_to'] instanceof Carbon) {
            $query->where(function ($submissionQuery) use ($filters) {
                if ($filters['date_from'] instanceof Carbon) {
                    $submissionQuery->where('created_at', '>=', $filters['date_from']);
                }

                if ($filters['date_to'] instanceof Carbon) {
                    $submissionQuery->where('created_at', '<=', $filters['date_to']);
                }

                $submissionQuery->orWhereHas('approvalSteps', function ($approvalQuery) use ($filters) {
                    if ($filters['date_from'] instanceof Carbon) {
                        $approvalQuery->where(function ($dateQuery) use ($filters) {
                            $dateQuery->where('created_at', '>=', $filters['date_from'])
                                ->orWhere('approved_at', '>=', $filters['date_from']);
                        });
                    }

                    if ($filters['date_to'] instanceof Carbon) {
                        $approvalQuery->where(function ($dateQuery) use ($filters) {
                            $dateQuery->where('created_at', '<=', $filters['date_to'])
                                ->orWhere('approved_at', '<=', $filters['date_to']);
                        });
                    }
                });
            });
        }

        $activities = collect();

        /** @var FormSubmission $submission */
        foreach ($query->get() as $submission) {
            $submission->loadMissing([
                'form.workflow',
                'user.roles',
                'approvalSteps.approver.roles',
            ]);

            $itSteps = $submission->approvalSteps
                ->filter(fn (ApprovalStep $step) => $this->isItRelatedStep($step))
                ->values();

            if ($itSteps->isEmpty()) {
                continue;
            }

            $createdActivity = $this->mapSubmissionCreatedActivity($submission, $itSteps);

            if ($this->activityWithinDateRange($createdActivity, $filters)) {
                $activities->push($createdActivity);
            }

            foreach ($itSteps as $step) {
                $enteredActivity = $this->mapSubmissionStepEnteredActivity($submission, $step);

                if ($this->activityWithinDateRange($enteredActivity, $filters)) {
                    $activities->push($enteredActivity);
                }

                if (in_array($step->status, ['approved', 'rejected'], true) && $step->approved_at !== null) {
                    $completedActivity = $this->mapSubmissionStepCompletedActivity($submission, $step);

                    if ($this->activityWithinDateRange($completedActivity, $filters)) {
                        $activities->push($completedActivity);
                    }
                }
            }
        }

        return $activities;
    }

    /**
     * @param  array<string, mixed>  $activity
     * @param  array<string, mixed>  $filters
     */
    private function activityWithinDateRange(array $activity, array $filters): bool
    {
        $occurredAt = Carbon::parse((string) $activity['occurred_at']);

        if ($filters['date_from'] instanceof Carbon && $occurredAt->lt($filters['date_from'])) {
            return false;
        }

        if ($filters['date_to'] instanceof Carbon && $occurredAt->gt($filters['date_to'])) {
            return false;
        }

        return true;
    }

    private function mapHelpdeskActivity(HelpdeskTicketUpdate $update): ?array
    {
        $ticket = $update->ticket;

        if (! $ticket) {
            return null;
        }

        $occurredAt = $update->created_at ?? $ticket->last_activity_at ?? $ticket->created_at ?? now();
        $actor = $update->user ?? $ticket->creator ?? $ticket->requester;
        $statusAtEvent = $update->type === 'status_changed'
            ? $this->helpdeskStatusLabel((string) data_get($update->meta, 'status'))
            : null;

        return [
            'id' => 'helpdesk-update-'.$update->id,
            'sort_at' => $occurredAt->timestamp,
            'occurred_at' => $occurredAt->toISOString(),
            'module' => 'helpdesk',
            'module_label' => 'Helpdesk',
            'activity_key' => 'helpdesk_'.$update->type,
            'activity_name' => $this->helpdeskActivityName($update),
            'reference_number' => $ticket->ticket_number ?: 'HD-'.str_pad((string) $ticket->id, 4, '0', STR_PAD_LEFT),
            'item_title' => $ticket->subject,
            'actor_name' => $actor?->name ?? 'System',
            'actor_role' => $this->userRoleLabel($actor),
            'requester_name' => $ticket->requester?->name ?? '-',
            'requester_department' => $ticket->requester?->department ?? '-',
            'it_owner' => $ticket->assignee?->name ?? 'Open Queue',
            'related_users' => $this->uniqueList([
                $ticket->requester?->name,
                $ticket->assignee?->name,
                $actor?->name,
            ]),
            'status_at_event_label' => $statusAtEvent,
            'current_status_label' => $this->helpdeskStatusLabel((string) $ticket->status),
            'summary' => trim((string) ($update->message ?: $this->helpdeskActivityName($update))),
            'notes' => in_array($update->type, ['comment', 'internal_note'], true)
                ? trim((string) $update->message)
                : null,
            'context_label' => $this->uniqueList([
                $this->helpdeskCategoryLabel((string) $ticket->category),
                $this->helpdeskChannelLabel((string) $ticket->channel),
            ], ' • '),
            'visibility_label' => $update->is_internal ? 'Internal IT' : 'Publik',
            'detail_url' => '/helpdesk/'.$ticket->id,
        ];
    }

    private function mapSubmissionCreatedActivity(FormSubmission $submission, Collection $itSteps): array
    {
        $occurredAt = $submission->created_at ?? now();
        $requester = $submission->user;
        $itTargets = $itSteps
            ->map(fn (ApprovalStep $step) => $this->submissionTargetLabel($step))
            ->filter()
            ->values()
            ->all();

        return [
            'id' => 'submission-created-'.$submission->id,
            'sort_at' => $occurredAt->timestamp,
            'occurred_at' => $occurredAt->toISOString(),
            'module' => 'submission',
            'module_label' => 'Pengajuan',
            'activity_key' => 'submission_created',
            'activity_name' => 'Pengajuan dibuat',
            'reference_number' => $this->submissionReferenceNumber($submission),
            'item_title' => $submission->form?->name ?? 'Form tidak diketahui',
            'actor_name' => $requester?->name ?? 'Pemohon',
            'actor_role' => $this->userRoleLabel($requester),
            'requester_name' => $requester?->name ?? '-',
            'requester_department' => $requester?->department ?? '-',
            'it_owner' => $this->uniqueList($itTargets),
            'related_users' => $this->uniqueList([
                $requester?->name,
                ...$itTargets,
            ]),
            'status_at_event_label' => 'Submitted',
            'current_status_label' => $this->submissionStatusLabel((string) $submission->current_status),
            'summary' => trim(($requester?->name ?? 'Pemohon').' membuat pengajuan yang melibatkan proses IT.'),
            'notes' => null,
            'context_label' => $submission->form?->workflow?->name ?? 'Workflow Pengajuan',
            'visibility_label' => 'Internal',
            'detail_url' => '/submissions/'.$submission->id,
        ];
    }

    private function mapSubmissionStepEnteredActivity(FormSubmission $submission, ApprovalStep $step): array
    {
        $occurredAt = $step->created_at ?? $submission->created_at ?? now();
        $targetLabel = $this->submissionTargetLabel($step);

        return [
            'id' => 'submission-step-entered-'.$step->id,
            'sort_at' => $occurredAt->timestamp,
            'occurred_at' => $occurredAt->toISOString(),
            'module' => 'submission',
            'module_label' => 'Pengajuan',
            'activity_key' => 'submission_step_entered',
            'activity_name' => 'Masuk ke tahap '.$step->step_name,
            'reference_number' => $this->submissionReferenceNumber($submission),
            'item_title' => $submission->form?->name ?? 'Form tidak diketahui',
            'actor_name' => $targetLabel,
            'actor_role' => $this->submissionTargetRoleLabel($step),
            'requester_name' => $submission->user?->name ?? '-',
            'requester_department' => $submission->user?->department ?? '-',
            'it_owner' => $targetLabel,
            'related_users' => $this->uniqueList([
                $submission->user?->name,
                $targetLabel,
            ]),
            'status_at_event_label' => $this->submissionStatusLabel((string) ($step->config_snapshot['entry_status'] ?? 'pending_it')),
            'current_status_label' => $this->submissionStatusLabel((string) $submission->current_status),
            'summary' => trim(($submission->form?->name ?? 'Pengajuan').' masuk ke antrean '.$targetLabel.'.'),
            'notes' => null,
            'context_label' => $submission->form?->workflow?->name ?? 'Workflow Pengajuan',
            'visibility_label' => 'Internal',
            'detail_url' => '/submissions/'.$submission->id,
        ];
    }

    private function mapSubmissionStepCompletedActivity(FormSubmission $submission, ApprovalStep $step): array
    {
        $occurredAt = $step->approved_at ?? $step->updated_at ?? $step->created_at ?? now();
        $actor = $step->approver;
        $actorName = $actor?->name ?? $this->submissionTargetLabel($step);
        $approved = $step->status === 'approved';
        $activityName = $approved
            ? $step->step_name.' diselesaikan'
            : $step->step_name.' ditolak';

        return [
            'id' => 'submission-step-completed-'.$step->id,
            'sort_at' => $occurredAt->timestamp,
            'occurred_at' => $occurredAt->toISOString(),
            'module' => 'submission',
            'module_label' => 'Pengajuan',
            'activity_key' => 'submission_step_completed',
            'activity_name' => $activityName,
            'reference_number' => $this->submissionReferenceNumber($submission),
            'item_title' => $submission->form?->name ?? 'Form tidak diketahui',
            'actor_name' => $actorName,
            'actor_role' => $this->userRoleLabel($actor) ?: $this->submissionTargetRoleLabel($step),
            'requester_name' => $submission->user?->name ?? '-',
            'requester_department' => $submission->user?->department ?? '-',
            'it_owner' => $actorName,
            'related_users' => $this->uniqueList([
                $submission->user?->name,
                $actorName,
            ]),
            'status_at_event_label' => $this->submissionStatusLabel($approved
                ? (string) ($step->config_snapshot['approve_status'] ?? 'completed')
                : (string) ($step->config_snapshot['reject_status'] ?? 'rejected')),
            'current_status_label' => $this->submissionStatusLabel((string) $submission->current_status),
            'summary' => $approved
                ? trim($actorName.' memproses langkah '.$step->step_name.'.')
                : trim($actorName.' menolak langkah '.$step->step_name.'.'),
            'notes' => filled($step->notes) ? trim((string) $step->notes) : null,
            'context_label' => $submission->form?->workflow?->name ?? 'Workflow Pengajuan',
            'visibility_label' => 'Internal',
            'detail_url' => '/submissions/'.$submission->id,
        ];
    }

    private function helpdeskActivityName(HelpdeskTicketUpdate $update): string
    {
        return match ($update->type) {
            'created' => 'Ticket bantuan dibuat',
            'assigned' => 'Penugasan ticket diperbarui',
            'status_changed' => 'Status ticket berubah',
            'priority_changed' => 'Prioritas ticket berubah',
            'internal_note' => 'Catatan internal IT ditambahkan',
            'comment' => 'Update ticket ditambahkan',
            default => Str::headline(str_replace('_', ' ', (string) $update->type)),
        };
    }

    private function isItRelatedStep(ApprovalStep $step): bool
    {
        $actorType = $step->actor_type ?? ($step->config_snapshot['actor_type'] ?? null);
        $actorValue = (string) ($step->actor_value ?? ($step->config_snapshot['actor_value'] ?? ''));
        $legacyRole = (string) ($step->approver_role ?? '');

        if ($actorType === 'role' && Str::lower($actorValue) === 'it staff') {
            return true;
        }

        if ($actorType === null && Str::lower($legacyRole) === 'it staff') {
            return true;
        }

        if ($actorType === 'user' && $actorValue !== '') {
            return $this->userHasRole((int) $actorValue, 'IT Staff');
        }

        return false;
    }

    private function userHasRole(int $userId, string $role): bool
    {
        if ($userId <= 0) {
            return false;
        }

        if (! array_key_exists($userId, $this->userCache)) {
            $this->userCache[$userId] = User::query()
                ->with('roles')
                ->find($userId);
        }

        return $this->userCache[$userId]?->hasRole($role) ?? false;
    }

    private function submissionTargetLabel(ApprovalStep $step): string
    {
        $actorType = $step->actor_type ?? ($step->config_snapshot['actor_type'] ?? null);
        $actorValue = (string) ($step->actor_value ?? ($step->config_snapshot['actor_value'] ?? ''));

        if ($actorType === 'user' && $actorValue !== '') {
            $targetUser = $this->userCache[(int) $actorValue] ?? null;

            if ($targetUser === null) {
                $targetUser = User::query()->with('roles')->find((int) $actorValue);
                $this->userCache[(int) $actorValue] = $targetUser;
            }

            if ($targetUser) {
                return $targetUser->name;
            }
        }

        return (string) ($step->actor_label
            ?? ($step->config_snapshot['actor_label'] ?? null)
            ?? $step->approver_role
            ?? 'IT Staff');
    }

    private function submissionTargetRoleLabel(ApprovalStep $step): string
    {
        $actorType = $step->actor_type ?? ($step->config_snapshot['actor_type'] ?? null);
        $actorValue = (string) ($step->actor_value ?? ($step->config_snapshot['actor_value'] ?? ''));

        if ($actorType === 'role' && $actorValue !== '') {
            return $actorValue;
        }

        if ($actorType === 'user' && $actorValue !== '') {
            $targetUser = $this->userCache[(int) $actorValue] ?? null;

            if ($targetUser === null) {
                $targetUser = User::query()->with('roles')->find((int) $actorValue);
                $this->userCache[(int) $actorValue] = $targetUser;
            }

            return $this->userRoleLabel($targetUser);
        }

        return (string) ($step->approver_role ?: 'IT Staff');
    }

    private function submissionReferenceNumber(FormSubmission $submission): string
    {
        return 'SUB-'.str_pad((string) $submission->id, 4, '0', STR_PAD_LEFT);
    }

    private function helpdeskCategoryLabel(string $value): string
    {
        return self::HELPDESK_CATEGORY_LABELS[$value] ?? Str::headline($value);
    }

    private function helpdeskChannelLabel(string $value): string
    {
        return self::HELPDESK_CHANNEL_LABELS[$value] ?? Str::headline($value);
    }

    private function helpdeskStatusLabel(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        return self::HELPDESK_STATUS_LABELS[$value] ?? Str::headline(str_replace('_', ' ', $value));
    }

    private function submissionStatusLabel(string $value): string
    {
        if ($value === '') {
            return '-';
        }

        return match ($value) {
            'submitted' => 'Submitted',
            'pending_it' => 'Pending IT',
            'pending_director' => 'Pending Director',
            'pending_accounting' => 'Pending Accounting',
            'pending_payment' => 'Pending Payment',
            'completed' => 'Completed',
            'rejected' => 'Rejected',
            default => Str::headline(str_replace('_', ' ', $value)),
        };
    }

    private function userRoleLabel(?User $user): string
    {
        if (! $user) {
            return '-';
        }

        $user->loadMissing('roles');

        $roles = $user->roles->pluck('name')->filter()->values()->all();

        return $roles === [] ? '-' : implode(', ', $roles);
    }

    /**
     * @param  array<int, string|null>  $values
     */
    private function uniqueList(array $values, string $separator = ', '): string
    {
        $items = collect($values)
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $items === [] ? '-' : implode($separator, $items);
    }

    private function filterOptions(): array
    {
        return [
            'modules' => [
                ['value' => 'all', 'label' => 'Semua Modul'],
                ['value' => 'helpdesk', 'label' => 'Helpdesk'],
                ['value' => 'submission', 'label' => 'Pengajuan'],
            ],
        ];
    }

    private function buildStats(Collection $activities): array
    {
        return [
            'total' => $activities->count(),
            'helpdesk' => $activities->where('module', 'helpdesk')->count(),
            'submission' => $activities->where('module', 'submission')->count(),
            'internal' => $activities->where('visibility_label', 'Internal IT')->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function describeFilters(array $filters): string
    {
        $segments = [];

        $segments[] = match ($filters['module'] ?? 'all') {
            'helpdesk' => 'Modul Helpdesk',
            'submission' => 'Modul Pengajuan',
            default => 'Semua modul',
        };

        if ($filters['date_from'] instanceof Carbon && $filters['date_to'] instanceof Carbon) {
            $segments[] = 'Periode '.$filters['date_from']->format('d/m/Y').' - '.$filters['date_to']->format('d/m/Y');
        } elseif ($filters['date_from'] instanceof Carbon) {
            $segments[] = 'Mulai '.$filters['date_from']->format('d/m/Y');
        } elseif ($filters['date_to'] instanceof Carbon) {
            $segments[] = 'Sampai '.$filters['date_to']->format('d/m/Y');
        }

        if (($filters['search'] ?? '') !== '') {
            $segments[] = 'Kata kunci: "'.$filters['search'].'"';
        }

        return implode(' • ', $segments);
    }
}

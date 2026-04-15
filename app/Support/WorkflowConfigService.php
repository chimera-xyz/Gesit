<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;

class WorkflowConfigService
{
    private const ACTOR_TYPES = [
        'requester',
        'role',
        'user',
        'system',
    ];

    public function normalize(array $workflowConfig): array
    {
        $rawSteps = collect($workflowConfig['steps'] ?? [])
            ->values()
            ->map(fn ($step, $index) => [
                'index' => $index,
                'step' => is_array($step) ? $step : [],
            ])
            ->sortBy(fn (array $item) => is_numeric($item['step']['step_number'] ?? null)
                ? (int) $item['step']['step_number']
                : $item['index'] + 1)
            ->values();

        $steps = $rawSteps
            ->map(fn (array $item, int $index) => $this->normalizeStep($item['step'], $index + 1))
            ->values()
            ->all();

        foreach ($steps as $index => $step) {
            $steps[$index]['next_step_key'] = $this->normalizeNullableString($step['next_step_key'])
                ?? ($steps[$index + 1]['step_key'] ?? null);
        }

        foreach ($steps as $index => $step) {
            if ($this->normalizeNullableString($step['approve_status']) !== null) {
                continue;
            }

            $nextStep = $this->findStepByKey($steps, $step['next_step_key']);
            $steps[$index]['approve_status'] = $nextStep['entry_status']
                ?? (($step['action'] ?? null) === 'complete' ? $step['entry_status'] : 'completed');
        }

        $statuses = collect($workflowConfig['statuses'] ?? [])
            ->merge(collect($steps)->flatMap(fn (array $step) => [
                $step['entry_status'] ?? null,
                $step['approve_status'] ?? null,
                $step['reject_status'] ?? null,
            ]))
            ->map(fn ($status) => $this->normalizeNullableString($status))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'version' => 2,
            'initial_step_key' => $steps[0]['step_key'] ?? null,
            'steps' => $steps,
            'statuses' => $statuses,
        ];
    }

    public function normalizeForStorage(array $workflowConfig): array
    {
        $normalized = $this->normalize($workflowConfig);
        $this->validate($normalized);

        return $normalized;
    }

    public function validate(array $workflowConfig): void
    {
        $steps = $workflowConfig['steps'] ?? [];

        if (!is_array($steps) || $steps === []) {
            throw new InvalidArgumentException('Workflow harus memiliki minimal satu step.');
        }

        $stepKeys = collect($steps)
            ->map(fn ($step) => $step['step_key'] ?? null)
            ->filter()
            ->values();

        if ($stepKeys->count() !== $stepKeys->unique()->count()) {
            throw new InvalidArgumentException('Setiap step workflow harus memiliki step key yang unik.');
        }

        foreach ($steps as $index => $step) {
            $stepNumber = $index + 1;
            $actorType = $step['actor_type'] ?? null;

            if (!in_array($actorType, self::ACTOR_TYPES, true)) {
                throw new InvalidArgumentException("Step {$stepNumber}: actor type tidak valid.");
            }

            if ($this->normalizeNullableString($step['name'] ?? null) === null) {
                throw new InvalidArgumentException("Step {$stepNumber}: nama step wajib diisi.");
            }

            if ($this->normalizeNullableString($step['entry_status'] ?? null) === null) {
                throw new InvalidArgumentException("Step {$stepNumber}: entry status wajib diisi.");
            }

            if ($actorType === 'role') {
                $roleName = $this->normalizeNullableString($step['actor_value'] ?? null);

                if ($roleName === null) {
                    throw new InvalidArgumentException("Step {$stepNumber}: role approver wajib dipilih.");
                }

                if (!Role::query()->where('name', $roleName)->exists()) {
                    throw new InvalidArgumentException("Step {$stepNumber}: role '{$roleName}' tidak ditemukan.");
                }
            }

            if ($actorType === 'user') {
                $userId = (int) ($step['actor_value'] ?? 0);

                if ($userId <= 0) {
                    throw new InvalidArgumentException("Step {$stepNumber}: user approver wajib dipilih.");
                }

                if (!User::query()->whereKey($userId)->exists()) {
                    throw new InvalidArgumentException("Step {$stepNumber}: user approver tidak ditemukan.");
                }
            }

            $nextStepKey = $this->normalizeNullableString($step['next_step_key'] ?? null);

            if ($nextStepKey !== null && !$stepKeys->contains($nextStepKey)) {
                throw new InvalidArgumentException("Step {$stepNumber}: next step '{$nextStepKey}' tidak ditemukan.");
            }

            if (($step['action'] ?? null) === 'complete' && $nextStepKey !== null) {
                throw new InvalidArgumentException("Step {$stepNumber}: step complete tidak boleh punya next step.");
            }
        }
    }

    public function actorLabel(array $step): string
    {
        $actorType = $step['actor_type'] ?? null;
        $actorValue = $step['actor_value'] ?? null;

        return match ($actorType) {
            'requester' => 'Requester',
            'role' => (string) $actorValue,
            'user' => $this->resolveUserLabel($actorValue),
            'system' => 'System',
            default => 'Unknown',
        };
    }

    private function normalizeStep(array $step, int $stepNumber): array
    {
        [$actorType, $actorValue] = $this->normalizeActor($step);
        $action = $this->normalizeNullableString($step['action'] ?? null) ?? ($actorType === 'requester' ? 'submit' : 'approve');
        $entryStatus = $this->normalizeNullableString($step['entry_status'] ?? null)
            ?? $this->normalizeNullableString($step['status'] ?? null)
            ?? $this->defaultEntryStatus($action, $actorType, $stepNumber);
        $rejectStatus = $this->normalizeNullableString($step['reject_status'] ?? null) ?? 'rejected';
        $stepKey = $this->normalizeNullableString($step['step_key'] ?? null)
            ?? $this->generateStepKey($step, $stepNumber);

        $normalized = [
            'step_key' => $stepKey,
            'step_number' => $stepNumber,
            'name' => $this->normalizeNullableString($step['name'] ?? null) ?? "Step {$stepNumber}",
            'actor_type' => $actorType,
            'actor_value' => $actorValue === null ? null : (string) $actorValue,
            'actor_label' => $this->actorLabel([
                'actor_type' => $actorType,
                'actor_value' => $actorValue,
            ]),
            'action' => $action,
            'entry_status' => $entryStatus,
            'approve_status' => $this->normalizeNullableString($step['approve_status'] ?? null),
            'reject_status' => $rejectStatus,
            'auto_complete' => (bool) ($step['auto_complete'] ?? in_array($action, ['submit', 'complete'], true) || $actorType === 'system'),
            'requires_signature' => $this->resolveRequiresSignature($step, $action),
            'notes_required' => (bool) ($step['notes_required'] ?? false),
            'allow_form_edit' => $this->resolveAllowFormEdit($step, $actorType, $actorValue, $action),
            'cta_label' => $this->normalizeNullableString($step['cta_label'] ?? null) ?? $this->defaultCtaLabel($action),
            'reject_label' => $this->normalizeNullableString($step['reject_label'] ?? null) ?? 'Tolak',
            'notes_placeholder' => $this->normalizeNullableString($step['notes_placeholder'] ?? null)
                ?? 'Tambahkan catatan untuk langkah ini.',
            'next_step_key' => $this->normalizeNullableString($step['next_step_key'] ?? null),
        ];

        if (array_key_exists('role', $step) && $normalized['actor_type'] === 'role' && $normalized['actor_value'] === null) {
            $normalized['actor_value'] = $this->normalizeNullableString($step['role']);
            $normalized['actor_label'] = (string) $normalized['actor_value'];
        }

        return $normalized;
    }

    private function normalizeActor(array $step): array
    {
        $explicitType = $this->normalizeNullableString($step['actor_type'] ?? null);
        $explicitValue = $this->normalizeNullableString($step['actor_value'] ?? null);

        if ($explicitType !== null) {
            return [$explicitType, $explicitValue];
        }

        $legacyRole = $this->normalizeNullableString($step['role'] ?? null);

        return match ($legacyRole) {
            'Requester' => ['requester', null],
            'System' => ['system', null],
            default => ['role', $legacyRole],
        };
    }

    private function resolveRequiresSignature(array $step, string $action): bool
    {
        if (array_key_exists('requires_signature', $step)) {
            return (bool) $step['requires_signature'];
        }

        return false;
    }

    private function resolveAllowFormEdit(array $step, string $actorType, ?string $actorValue, string $action): bool
    {
        if (array_key_exists('allow_form_edit', $step)) {
            return (bool) $step['allow_form_edit'];
        }

        return $actorType === 'role'
            && $actorValue === 'IT Staff'
            && $action === 'review';
    }

    private function defaultEntryStatus(string $action, string $actorType, int $stepNumber): string
    {
        return match (true) {
            $action === 'submit' => 'submitted',
            $action === 'complete' => 'completed',
            default => 'pending_' . Str::slug($actorType . '_' . $stepNumber, '_'),
        };
    }

    private function defaultCtaLabel(string $action): string
    {
        return match ($action) {
            'submit' => 'Kirim Pengajuan',
            'review' => 'Simpan Review',
            'process', 'process_payment' => 'Proses Langkah',
            'mark_paid' => 'Tandai Selesai',
            'complete' => 'Selesai',
            default => 'Setujui',
        };
    }

    private function generateStepKey(array $step, int $stepNumber): string
    {
        $base = Str::slug((string) ($step['name'] ?? $step['action'] ?? "step-{$stepNumber}"), '_');

        return $base !== '' ? $base : "step_{$stepNumber}";
    }

    private function resolveUserLabel(string|int|null $userId): string
    {
        $resolvedId = (int) $userId;

        if ($resolvedId <= 0) {
            return 'User';
        }

        return User::query()->whereKey($resolvedId)->value('name') ?? "User #{$resolvedId}";
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function findStepByKey(array $steps, ?string $stepKey): ?array
    {
        if ($stepKey === null) {
            return null;
        }

        foreach ($steps as $step) {
            if (($step['step_key'] ?? null) === $stepKey) {
                return $step;
            }
        }

        return null;
    }
}

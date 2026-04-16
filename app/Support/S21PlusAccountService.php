<?php

namespace App\Support;

use App\Models\KnowledgeConversation;
use App\Models\KnowledgeConversationMessage;
use App\Models\S21PlusUnblockAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

class S21PlusAccountService
{
    private const ELIGIBLE_LOGIN_RETRY = 3;

    public function inspectOwnAccount(User $user, array $context = []): array
    {
        $mappedUserId = $this->normalizeUserId($user->s21plus_user_id);

        if ($mappedUserId === null) {
            return $this->auditAndReturn($user, $context, [
                'request_type' => 'check_status',
                'status' => 'failed',
                'result_code' => 'mapping_missing',
                'message' => 'Akun GESIT Anda belum memiliki UserID S21Plus yang terdaftar.',
            ]);
        }

        try {
            $account = $this->findAccount($mappedUserId);
        } catch (Throwable $exception) {
            return $this->auditAndReturn($user, $context, [
                's21plus_user_id' => $mappedUserId,
                'request_type' => 'check_status',
                'status' => 'failed',
                'result_code' => 'service_unavailable',
                'message' => 'Koneksi ke sistem S21Plus sedang tidak tersedia.',
            ], $exception);
        }

        if ($account === null) {
            return $this->auditAndReturn($user, $context, [
                's21plus_user_id' => $mappedUserId,
                'request_type' => 'check_status',
                'status' => 'failed',
                'result_code' => 'account_not_found',
                'message' => 'Akun S21Plus yang terhubung ke profil Anda tidak ditemukan.',
            ]);
        }

        $snapshot = $this->snapshot($account);

        if ($snapshot['is_enabled']) {
            return $this->auditAndReturn($user, $context, [
                's21plus_user_id' => $mappedUserId,
                'request_type' => 'check_status',
                'before_is_enabled' => $snapshot['is_enabled'],
                'before_login_retry' => $snapshot['login_retry'],
                'after_is_enabled' => $snapshot['is_enabled'],
                'after_login_retry' => $snapshot['login_retry'],
                'status' => 'completed',
                'result_code' => 'account_active',
                'message' => 'Akun S21Plus Anda saat ini aktif dan tidak terblokir.',
            ]);
        }

        if ($snapshot['login_retry'] === self::ELIGIBLE_LOGIN_RETRY) {
            return $this->auditAndReturn($user, $context, [
                's21plus_user_id' => $mappedUserId,
                'request_type' => 'check_status',
                'before_is_enabled' => $snapshot['is_enabled'],
                'before_login_retry' => $snapshot['login_retry'],
                'after_is_enabled' => $snapshot['is_enabled'],
                'after_login_retry' => $snapshot['login_retry'],
                'status' => 'completed',
                'result_code' => 'blocked_confirmed',
                'message' => 'Akun S21Plus Anda terdeteksi terblokir dan siap dibuka blokir.',
            ]);
        }

        return $this->auditAndReturn($user, $context, [
            's21plus_user_id' => $mappedUserId,
            'request_type' => 'check_status',
            'before_is_enabled' => $snapshot['is_enabled'],
            'before_login_retry' => $snapshot['login_retry'],
            'after_is_enabled' => $snapshot['is_enabled'],
            'after_login_retry' => $snapshot['login_retry'],
            'status' => 'failed',
            'result_code' => 'blocked_unexpected_state',
            'message' => 'Status akun S21Plus Anda tidak sesuai kriteria self-service unblock.',
        ]);
    }

    public function unlockOwnAccount(User $user, array $context = []): array
    {
        $mappedUserId = $this->normalizeUserId($user->s21plus_user_id);

        if ($mappedUserId === null) {
            return $this->auditAndReturn($user, $context, [
                'request_type' => 'unlock',
                'status' => 'failed',
                'result_code' => 'mapping_missing',
                'message' => 'Akun GESIT Anda belum memiliki UserID S21Plus yang terdaftar.',
            ]);
        }

        try {
            $beforeAccount = $this->findAccount($mappedUserId);
        } catch (Throwable $exception) {
            return $this->auditAndReturn($user, $context, [
                's21plus_user_id' => $mappedUserId,
                'request_type' => 'unlock',
                'status' => 'failed',
                'result_code' => 'service_unavailable',
                'message' => 'Koneksi ke sistem S21Plus sedang tidak tersedia.',
            ], $exception);
        }

        if ($beforeAccount === null) {
            return $this->auditAndReturn($user, $context, [
                's21plus_user_id' => $mappedUserId,
                'request_type' => 'unlock',
                'status' => 'failed',
                'result_code' => 'account_not_found',
                'message' => 'Akun S21Plus yang terhubung ke profil Anda tidak ditemukan.',
            ]);
        }

        $beforeSnapshot = $this->snapshot($beforeAccount);

        if ($beforeSnapshot['is_enabled']) {
            return $this->auditAndReturn($user, $context, [
                's21plus_user_id' => $mappedUserId,
                'request_type' => 'unlock',
                'before_is_enabled' => $beforeSnapshot['is_enabled'],
                'before_login_retry' => $beforeSnapshot['login_retry'],
                'after_is_enabled' => $beforeSnapshot['is_enabled'],
                'after_login_retry' => $beforeSnapshot['login_retry'],
                'status' => 'failed',
                'result_code' => 'account_active',
                'message' => 'Akun S21Plus Anda sudah aktif sehingga tidak perlu dibuka blokir.',
            ]);
        }

        if ($beforeSnapshot['login_retry'] !== self::ELIGIBLE_LOGIN_RETRY) {
            return $this->auditAndReturn($user, $context, [
                's21plus_user_id' => $mappedUserId,
                'request_type' => 'unlock',
                'before_is_enabled' => $beforeSnapshot['is_enabled'],
                'before_login_retry' => $beforeSnapshot['login_retry'],
                'after_is_enabled' => $beforeSnapshot['is_enabled'],
                'after_login_retry' => $beforeSnapshot['login_retry'],
                'status' => 'failed',
                'result_code' => 'blocked_unexpected_state',
                'message' => 'Status akun S21Plus Anda tidak sesuai kriteria self-service unblock.',
            ]);
        }

        try {
            if ($this->shouldUseOdbcBridge()) {
                $unlockResult = $this->unlockViaBridge($mappedUserId);
                $afterAccount = $this->bridgeUserToAccountArray($unlockResult['after'] ?? null);
            } else {
                DB::connection('s21plus')
                    ->table('User')
                    ->where('UserID', $mappedUserId)
                    ->update([
                        'IsEnabled' => 1,
                        'LoginRetry' => 0,
                    ]);

                $afterAccount = $this->findAccount($mappedUserId);
            }
        } catch (Throwable $exception) {
            return $this->auditAndReturn($user, $context, [
                's21plus_user_id' => $mappedUserId,
                'request_type' => 'unlock',
                'before_is_enabled' => $beforeSnapshot['is_enabled'],
                'before_login_retry' => $beforeSnapshot['login_retry'],
                'status' => 'failed',
                'result_code' => 'unlock_failed',
                'message' => 'Proses unblock belum berhasil dijalankan di sistem S21Plus.',
            ], $exception);
        }

        $afterSnapshot = $afterAccount ? $this->snapshot($afterAccount) : [
            'is_enabled' => null,
            'login_retry' => null,
        ];

        if ($afterSnapshot['is_enabled'] === true && $afterSnapshot['login_retry'] === 0) {
            return $this->auditAndReturn($user, $context, [
                's21plus_user_id' => $mappedUserId,
                'request_type' => 'unlock',
                'before_is_enabled' => $beforeSnapshot['is_enabled'],
                'before_login_retry' => $beforeSnapshot['login_retry'],
                'after_is_enabled' => $afterSnapshot['is_enabled'],
                'after_login_retry' => $afterSnapshot['login_retry'],
                'status' => 'completed',
                'result_code' => 'unlock_success',
                'message' => 'Akun S21Plus berhasil dibuka blokir.',
            ]);
        }

        return $this->auditAndReturn($user, $context, [
            's21plus_user_id' => $mappedUserId,
            'request_type' => 'unlock',
            'before_is_enabled' => $beforeSnapshot['is_enabled'],
            'before_login_retry' => $beforeSnapshot['login_retry'],
            'after_is_enabled' => $afterSnapshot['is_enabled'],
            'after_login_retry' => $afterSnapshot['login_retry'],
            'status' => 'failed',
            'result_code' => 'verification_failed',
            'message' => 'Verifikasi hasil unblock tidak sesuai dengan status yang diharapkan.',
        ]);
    }

    private function findAccount(string $userId): ?array
    {
        if ($this->shouldUseOdbcBridge()) {
            return $this->inspectViaBridge($userId);
        }

        $this->guardConnectionConfiguration();

        $record = DB::connection('s21plus')
            ->table('User')
            ->where('UserID', $userId)
            ->first([
                'UserID',
                'IsEnabled',
                'LoginRetry',
            ]);

        return $record ? (array) $record : null;
    }

    private function inspectViaBridge(string $userId): ?array
    {
        $payload = $this->runOdbcBridge('inspect', $userId);

        return $this->bridgeUserToAccountArray($payload['payload'] ?? null);
    }

    private function unlockViaBridge(string $userId): array
    {
        $payload = $this->runOdbcBridge('unlock', $userId);

        return is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
    }

    private function runOdbcBridge(string $action, ?string $userId = null): array
    {
        $helperPhp = (string) config('database.connections.s21plus.helper_php', '');
        $helperScript = (string) config('database.connections.s21plus.helper_script', '');

        if ($helperPhp === '' || ! is_file($helperPhp)) {
            throw new \RuntimeException('S21Plus helper PHP binary is not available.');
        }

        if ($helperScript === '' || ! is_file($helperScript)) {
            throw new \RuntimeException('S21Plus ODBC bridge script is not available.');
        }

        $command = [$helperPhp, $helperScript, $action];

        if ($userId !== null) {
            $command[] = $userId;
        }

        $process = new Process($command, base_path());
        $process->setTimeout(20);
        $process->run();

        $rawOutput = trim($process->getOutput());

        if ($rawOutput === '') {
            $rawOutput = trim($process->getErrorOutput());
        }

        $decoded = json_decode($rawOutput, true);

        if (! is_array($decoded)) {
            throw new \RuntimeException(sprintf('Unexpected S21Plus helper response: %s', $rawOutput));
        }

        if (($decoded['ok'] ?? false) !== true) {
            throw new \RuntimeException((string) ($decoded['error'] ?? 'S21Plus helper failed.'));
        }

        return $decoded;
    }

    private function bridgeUserToAccountArray(mixed $payload): ?array
    {
        if (! is_array($payload) || ($payload['found'] ?? false) !== true) {
            return null;
        }

        return [
            'UserID' => (string) ($payload['user_id'] ?? ''),
            'IsEnabled' => ($payload['is_enabled'] ?? false) ? 1 : 0,
            'LoginRetry' => (int) ($payload['login_retry'] ?? 0),
        ];
    }

    private function snapshot(array $account): array
    {
        return [
            'is_enabled' => array_key_exists('IsEnabled', $account) ? ((int) $account['IsEnabled']) === 1 : null,
            'login_retry' => array_key_exists('LoginRetry', $account) ? (int) $account['LoginRetry'] : null,
        ];
    }

    private function auditAndReturn(User $user, array $context, array $result, ?Throwable $exception = null): array
    {
        [$conversationId, $messageId] = $this->resolveAuditContext($context);

        if ($exception !== null) {
            Log::warning('S21Plus account service error', [
                'gesit_user_id' => $user->id,
                's21plus_user_id' => $result['s21plus_user_id'] ?? $user->s21plus_user_id,
                'request_type' => $result['request_type'] ?? null,
                'result_code' => $result['result_code'] ?? null,
                'error' => $exception->getMessage(),
            ]);
        }

        S21PlusUnblockAuditLog::query()->create([
            'gesit_user_id' => $user->id,
            'gesit_user_name' => $user->name,
            's21plus_user_id' => $result['s21plus_user_id'] ?? $this->normalizeUserId($user->s21plus_user_id),
            'knowledge_conversation_id' => $conversationId,
            'knowledge_conversation_message_id' => $messageId,
            'request_type' => $result['request_type'],
            'before_is_enabled' => $result['before_is_enabled'] ?? null,
            'before_login_retry' => $result['before_login_retry'] ?? null,
            'after_is_enabled' => $result['after_is_enabled'] ?? null,
            'after_login_retry' => $result['after_login_retry'] ?? null,
            'status' => $result['status'],
            'result_code' => $result['result_code'],
            'message' => $result['message'],
        ]);

        return [
            'request_type' => $result['request_type'],
            'status' => $result['status'],
            'result_code' => $result['result_code'],
            'message' => $result['message'],
            's21plus_user_id' => $result['s21plus_user_id'] ?? $this->normalizeUserId($user->s21plus_user_id),
            'before' => [
                'is_enabled' => $result['before_is_enabled'] ?? null,
                'login_retry' => $result['before_login_retry'] ?? null,
            ],
            'after' => [
                'is_enabled' => $result['after_is_enabled'] ?? null,
                'login_retry' => $result['after_login_retry'] ?? null,
            ],
        ];
    }

    private function resolveAuditContext(array $context): array
    {
        $conversationId = isset($context['conversation_id']) ? (int) $context['conversation_id'] : null;
        $messageId = isset($context['message_id']) ? (int) $context['message_id'] : null;

        if ($conversationId !== null && $conversationId > 0) {
            if (! KnowledgeConversation::query()->whereKey($conversationId)->exists()) {
                $conversationId = null;
            }
        } else {
            $conversationId = null;
        }

        if ($messageId !== null && $messageId > 0) {
            $messageQuery = KnowledgeConversationMessage::query()->whereKey($messageId);

            if ($conversationId !== null) {
                $messageQuery->where('knowledge_conversation_id', $conversationId);
            }

            if (! $messageQuery->exists()) {
                $messageId = null;
            }
        } else {
            $messageId = null;
        }

        return [$conversationId, $messageId];
    }

    private function normalizeUserId(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function guardConnectionConfiguration(): void
    {
        if ($this->shouldUseOdbcBridge()) {
            return;
        }

        $driver = config('database.connections.s21plus.driver');

        $requiredKeys = $driver === 'sqlite'
            ? ['database.connections.s21plus.database']
            : [
                'database.connections.s21plus.host',
                'database.connections.s21plus.database',
                'database.connections.s21plus.username',
                'database.connections.s21plus.password',
            ];

        foreach ($requiredKeys as $key) {
            $value = config($key);

            if ($value === null || $value === '') {
                throw new \RuntimeException(sprintf('Missing S21Plus database configuration for [%s].', $key));
            }
        }
    }

    private function shouldUseOdbcBridge(): bool
    {
        return config('database.connections.s21plus.driver') === 'sqlsrv'
            && ! extension_loaded('pdo_sqlsrv')
            && is_file((string) config('database.connections.s21plus.helper_php', ''))
            && is_file((string) config('database.connections.s21plus.helper_script', ''));
    }
}

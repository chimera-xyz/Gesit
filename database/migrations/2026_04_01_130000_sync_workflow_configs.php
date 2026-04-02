<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Workflow;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $procurement = Workflow::query()->where('slug', 'procurement')->first();

        if ($procurement) {
            $procurement->update([
                'name' => 'Hardware/Software Procurement',
                'description' => 'Standard workflow for hardware and software procurement requests',
                'workflow_config' => [
                    'steps' => [
                        [
                            'step_number' => 1,
                            'name' => 'Pengajuan Dibuat',
                            'role' => 'Requester',
                            'action' => 'submit',
                            'status' => 'submitted',
                            'auto_complete' => true,
                        ],
                        [
                            'step_number' => 2,
                            'name' => 'Review Kelayakan IT',
                            'role' => 'IT Staff',
                            'action' => 'review',
                            'status' => 'pending_it',
                            'cta_label' => 'Lanjutkan ke Direktur',
                            'notes_placeholder' => 'Tuliskan hasil review IT dan catatan teknis.',
                            'requires_signature' => true,
                        ],
                        [
                            'step_number' => 3,
                            'name' => 'Persetujuan Direktur Operasional',
                            'role' => 'Operational Director',
                            'action' => 'approve',
                            'status' => 'pending_director',
                            'cta_label' => 'Setujui untuk Accounting',
                            'notes_placeholder' => 'Tuliskan pertimbangan approval direktur.',
                            'requires_signature' => true,
                        ],
                        [
                            'step_number' => 4,
                            'name' => 'Proses Pembayaran Accounting',
                            'role' => 'Accounting',
                            'action' => 'process_payment',
                            'status' => 'pending_accounting',
                            'cta_label' => 'Proses Pembayaran',
                            'notes_placeholder' => 'Isi catatan proses pembayaran, invoice, atau vendor.',
                            'requires_signature' => false,
                        ],
                        [
                            'step_number' => 5,
                            'name' => 'Konfirmasi Sudah Bayar',
                            'role' => 'Accounting',
                            'action' => 'mark_paid',
                            'status' => 'pending_payment',
                            'cta_label' => 'Tandai Sudah Bayar',
                            'notes_placeholder' => 'Isi referensi pembayaran atau tanggal transfer.',
                            'requires_signature' => true,
                        ],
                        [
                            'step_number' => 6,
                            'name' => 'Selesai',
                            'role' => 'System',
                            'action' => 'complete',
                            'status' => 'completed',
                            'auto_complete' => true,
                        ],
                    ],
                    'statuses' => [
                        'submitted',
                        'pending_it',
                        'pending_director',
                        'pending_accounting',
                        'pending_payment',
                        'completed',
                        'rejected',
                    ],
                ],
            ]);
        }

        $passwordReset = Workflow::query()->where('slug', 'password_reset')->first();

        if ($passwordReset) {
            $passwordReset->update([
                'name' => 'Password Reset Request',
                'description' => 'Workflow for password reset requests',
                'workflow_config' => [
                    'steps' => [
                        [
                            'step_number' => 1,
                            'name' => 'Pengajuan Dibuat',
                            'role' => 'Requester',
                            'action' => 'submit',
                            'status' => 'submitted',
                            'auto_complete' => true,
                        ],
                        [
                            'step_number' => 2,
                            'name' => 'Proses Reset oleh IT',
                            'role' => 'IT Staff',
                            'action' => 'process',
                            'status' => 'pending_it',
                            'cta_label' => 'Selesaikan Reset Password',
                            'requires_signature' => false,
                        ],
                        [
                            'step_number' => 3,
                            'name' => 'Selesai',
                            'role' => 'System',
                            'action' => 'complete',
                            'status' => 'completed',
                            'auto_complete' => true,
                        ],
                    ],
                    'statuses' => [
                        'submitted',
                        'pending_it',
                        'completed',
                        'rejected',
                    ],
                ],
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No rollback to outdated workflow config.
    }
};

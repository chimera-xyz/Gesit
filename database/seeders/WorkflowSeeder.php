<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Workflow;

class WorkflowSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create procurement workflow
        Workflow::updateOrCreate([
            'slug' => 'procurement',
        ], [
            'name' => 'Hardware/Software Procurement',
            'description' => 'Standard workflow for hardware and software procurement requests',
            'workflow_config' => [
                'version' => 2,
                'steps' => [
                    [
                        'step_key' => 'submit_request',
                        'name' => 'Pengajuan Dibuat',
                        'actor_type' => 'requester',
                        'action' => 'submit',
                        'entry_status' => 'submitted',
                        'approve_status' => 'pending_it',
                        'auto_complete' => true,
                    ],
                    [
                        'step_key' => 'it_review',
                        'name' => 'Review Kelayakan IT',
                        'actor_type' => 'role',
                        'actor_value' => 'IT Staff',
                        'action' => 'review',
                        'entry_status' => 'pending_it',
                        'approve_status' => 'pending_director',
                        'reject_status' => 'rejected',
                        'cta_label' => 'Lanjutkan ke Direktur',
                        'notes_placeholder' => 'Tuliskan hasil review IT dan catatan teknis.',
                        'requires_signature' => true,
                        'notes_required' => true,
                        'allow_form_edit' => true,
                    ],
                    [
                        'step_key' => 'director_approval',
                        'name' => 'Persetujuan Direktur Operasional',
                        'actor_type' => 'role',
                        'actor_value' => 'Operational Director',
                        'action' => 'approve',
                        'entry_status' => 'pending_director',
                        'approve_status' => 'pending_accounting',
                        'reject_status' => 'rejected',
                        'cta_label' => 'Setujui untuk Accounting',
                        'notes_placeholder' => 'Tuliskan pertimbangan approval direktur.',
                        'requires_signature' => true,
                        'notes_required' => true,
                    ],
                    [
                        'step_key' => 'accounting_processing',
                        'name' => 'Proses Pembayaran Accounting',
                        'actor_type' => 'role',
                        'actor_value' => 'Accounting',
                        'action' => 'process_payment',
                        'entry_status' => 'pending_accounting',
                        'approve_status' => 'pending_payment',
                        'reject_status' => 'rejected',
                        'cta_label' => 'Proses Pembayaran',
                        'notes_placeholder' => 'Isi catatan proses pembayaran, invoice, atau vendor.',
                        'requires_signature' => false,
                        'notes_required' => true,
                    ],
                    [
                        'step_key' => 'accounting_payment_confirmation',
                        'name' => 'Konfirmasi Sudah Bayar',
                        'actor_type' => 'role',
                        'actor_value' => 'Accounting',
                        'action' => 'mark_paid',
                        'entry_status' => 'pending_payment',
                        'approve_status' => 'completed',
                        'reject_status' => 'rejected',
                        'cta_label' => 'Tandai Sudah Bayar',
                        'notes_placeholder' => 'Isi referensi pembayaran atau tanggal transfer.',
                        'requires_signature' => true,
                        'notes_required' => true,
                    ],
                    [
                        'step_key' => 'complete',
                        'name' => 'Selesai',
                        'actor_type' => 'system',
                        'action' => 'complete',
                        'entry_status' => 'completed',
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
            'is_active' => true,
        ]);

        // Create password reset workflow
        Workflow::updateOrCreate([
            'slug' => 'password_reset',
        ], [
            'name' => 'Password Reset Request',
            'description' => 'Workflow for password reset requests',
            'workflow_config' => [
                'version' => 2,
                'steps' => [
                    [
                        'step_key' => 'submit_request',
                        'name' => 'Pengajuan Dibuat',
                        'actor_type' => 'requester',
                        'action' => 'submit',
                        'entry_status' => 'submitted',
                        'approve_status' => 'pending_it',
                        'auto_complete' => true,
                    ],
                    [
                        'step_key' => 'it_password_reset',
                        'name' => 'Proses Reset oleh IT',
                        'actor_type' => 'role',
                        'actor_value' => 'IT Staff',
                        'action' => 'process',
                        'entry_status' => 'pending_it',
                        'approve_status' => 'completed',
                        'cta_label' => 'Selesaikan Reset Password',
                        'requires_signature' => false,
                        'notes_required' => true,
                    ],
                    [
                        'step_key' => 'complete',
                        'name' => 'Selesai',
                        'actor_type' => 'system',
                        'action' => 'complete',
                        'entry_status' => 'completed',
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
            'is_active' => true,
        ]);
    }
}

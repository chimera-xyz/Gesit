<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Form;
use App\Models\Workflow;

class FormSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $procurementWorkflow = Workflow::where('slug', 'procurement')->first();
        $passwordResetWorkflow = Workflow::where('slug', 'password_reset')->first();

        // Create Hardware/Software Procurement Form
        Form::updateOrCreate([
            'slug' => 'hardware-software-procurement',
        ], [
            'name' => 'Form Pengadaan Hardware / Software',
            'description' => 'Pengajuan kebutuhan hardware atau software dengan alur review IT, persetujuan direktur operasional, dan proses accounting.',
            'form_config' => [
                'fields' => [
                    [
                        'id' => 'employee_name',
                        'type' => 'text',
                        'label' => 'Nama Karyawan',
                        'required' => true,
                        'readonly' => true,
                        'auto_fill' => 'user.name',
                        'validation' => 'string|max:255',
                    ],
                    [
                        'id' => 'department',
                        'type' => 'text',
                        'label' => 'Departemen',
                        'required' => true,
                        'readonly' => true,
                        'auto_fill' => 'user.department',
                        'validation' => 'string|max:255',
                    ],
                    [
                        'id' => 'request_date',
                        'type' => 'date',
                        'label' => 'Tanggal Pengajuan',
                        'required' => true,
                        'readonly' => true,
                        'auto_fill' => 'today',
                        'validation' => 'date',
                    ],
                    [
                        'id' => 'item_name',
                        'type' => 'text',
                        'label' => 'Nama Barang',
                        'required' => true,
                        'placeholder' => 'Contoh: Laptop kerja divisi marketing',
                        'validation' => 'string|max:255',
                    ],
                    [
                        'id' => 'item_type',
                        'type' => 'radio',
                        'label' => 'Tipe Barang',
                        'options' => ['Hardware', 'Software'],
                        'required' => true,
                    ],
                    [
                        'id' => 'quantity',
                        'type' => 'number',
                        'label' => 'Jumlah',
                        'required' => true,
                        'placeholder' => '1',
                        'validation' => 'integer|min:1',
                    ],
                    [
                        'id' => 'specifications',
                        'type' => 'textarea',
                        'label' => 'Spesifikasi yang diinginkan',
                        'required' => true,
                        'placeholder' => 'Jelaskan spesifikasi, merk yang diinginkan, lisensi, kapasitas, atau kebutuhan teknis lain.',
                        'validation' => 'string',
                    ],
                    [
                        'id' => 'reason',
                        'type' => 'textarea',
                        'label' => 'Alasan ingin membeli',
                        'required' => true,
                        'placeholder' => 'Jelaskan kebutuhan bisnis, kendala saat ini, dan dampak jika tidak dipenuhi.',
                        'validation' => 'string',
                    ],
                    [
                        'id' => 'urgency',
                        'type' => 'select',
                        'label' => 'Status Urgensi',
                        'options' => ['Urgent', 'Normal', 'Slow'],
                        'required' => true,
                    ],
                    [
                        'id' => 'needed_by_date',
                        'type' => 'date',
                        'label' => 'Dibutuhkan Sebelum',
                        'required' => false,
                        'validation' => 'date',
                    ],
                    [
                        'id' => 'estimated_cost',
                        'type' => 'number',
                        'label' => 'Estimasi Biaya (Rp)',
                        'required' => true,
                        'validation' => 'numeric|min:0',
                    ],
                    [
                        'id' => 'vendor_preference',
                        'type' => 'text',
                        'label' => 'Vendor / Referensi (Opsional)',
                        'required' => false,
                        'placeholder' => 'Contoh: Tokopedia, Bhinneka, Microsoft 365 Business',
                        'validation' => 'nullable|string|max:255',
                    ],
                ],
            ],
            'workflow_id' => $procurementWorkflow->id,
            'is_active' => true,
        ]);

        // Create Password Reset Request Form
        Form::updateOrCreate([
            'slug' => 'password-reset-request',
        ], [
            'name' => 'Password Reset Request',
            'description' => 'Form for requesting password reset',
            'form_config' => [
                'fields' => [
                    [
                        'id' => 'username',
                        'type' => 'text',
                        'label' => 'Username',
                        'required' => true,
                        'validation' => 'string|max:255',
                    ],
                    [
                        'id' => 'employee_name',
                        'type' => 'text',
                        'label' => 'Nama Karyawan',
                        'required' => true,
                        'readonly' => true,
                        'auto_fill' => 'user.name',
                        'validation' => 'string|max:255',
                    ],
                    [
                        'id' => 'reason',
                        'type' => 'textarea',
                        'label' => 'Alasan reset password',
                        'required' => true,
                        'validation' => 'string',
                    ],
                ],
            ],
            'workflow_id' => $passwordResetWorkflow->id,
            'is_active' => true,
        ]);
    }
}

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
            'name' => 'Hardware/Software Procurement',
            'description' => 'Form for requesting hardware and software procurement',
            'form_config' => [
                'fields' => [
                    [
                        'id' => 'employee_name',
                        'type' => 'text',
                        'label' => 'Nama Karyawan',
                        'required' => true,
                        'validation' => 'string|max:255',
                    ],
                    [
                        'id' => 'department',
                        'type' => 'text',
                        'label' => 'Departemen',
                        'required' => true,
                        'validation' => 'string|max:255',
                    ],
                    [
                        'id' => 'item_name',
                        'type' => 'text',
                        'label' => 'Nama Barang',
                        'required' => true,
                        'validation' => 'string|max:255',
                    ],
                    [
                        'id' => 'item_type',
                        'type' => 'select',
                        'label' => 'Tipe Barang',
                        'options' => ['Hardware', 'Software'],
                        'required' => true,
                    ],
                    [
                        'id' => 'quantity',
                        'type' => 'number',
                        'label' => 'Jumlah',
                        'required' => true,
                        'validation' => 'integer|min:1',
                    ],
                    [
                        'id' => 'specifications',
                        'type' => 'textarea',
                        'label' => 'Spesifikasi yang diinginkan',
                        'required' => true,
                        'validation' => 'string',
                    ],
                    [
                        'id' => 'reason',
                        'type' => 'textarea',
                        'label' => 'Alasan ingin membeli',
                        'required' => true,
                        'validation' => 'string',
                    ],
                    [
                        'id' => 'urgency',
                        'type' => 'select',
                        'label' => 'Status Urgensi',
                        'options' => ['Urgent', 'Slow', 'Normal'],
                        'required' => true,
                    ],
                    [
                        'id' => 'estimated_cost',
                        'type' => 'number',
                        'label' => 'Estimasi Biaya (Rp)',
                        'required' => true,
                        'validation' => 'numeric|min:0',
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

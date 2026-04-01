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
                'steps' => [
                    [
                        'step_number' => 1,
                        'name' => 'Initial Submission',
                        'role' => 'all',
                        'action' => 'submit',
                    ],
                    [
                        'step_number' => 2,
                        'name' => 'IT Review',
                        'role' => 'IT Staff',
                        'action' => 'approve_reject',
                        'required_fields' => ['it_notes', 'signature'],
                    ],
                    [
                        'step_number' => 3,
                        'name' => 'Director Approval',
                        'role' => 'Operational Director',
                        'action' => 'approve_reject',
                        'required_fields' => ['director_notes', 'signature'],
                    ],
                    [
                        'step_number' => 4,
                        'name' => 'Accounting Process',
                        'role' => 'Accounting',
                        'action' => 'process_payment',
                        'required_fields' => ['accounting_notes', 'payment_proof', 'signature'],
                    ],
                    [
                        'step_number' => 5,
                        'name' => 'Completed',
                        'role' => 'none',
                        'action' => 'complete',
                    ],
                ],
                'statuses' => [
                    'submitted',
                    'pending_it',
                    'pending_director',
                    'pending_accounting',
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
                'steps' => [
                    [
                        'step_number' => 1,
                        'name' => 'Initial Submission',
                        'role' => 'all',
                        'action' => 'submit',
                    ],
                    [
                        'step_number' => 2,
                        'name' => 'IT Process',
                        'role' => 'IT Staff',
                        'action' => 'process',
                        'required_fields' => ['it_notes'],
                    ],
                    [
                        'step_number' => 3,
                        'name' => 'Completed',
                        'role' => 'none',
                        'action' => 'complete',
                    ],
                ],
                'statuses' => [
                    'submitted',
                    'in_progress',
                    'completed',
                    'rejected',
                ],
            ],
            'is_active' => true,
        ]);
    }
}

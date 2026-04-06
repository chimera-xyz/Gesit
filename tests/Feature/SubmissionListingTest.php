<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\User;
use Database\Seeders\FormSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubmissionListingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(WorkflowSeeder::class);
        $this->seed(FormSeeder::class);
    }

    public function test_employee_can_search_their_own_submissions_and_paginate_results(): void
    {
        $employee = $this->makeUserWithRole('Employee', [
            'department' => 'General Affairs',
            'employee_id' => 'EMP-301',
        ]);
        $otherEmployee = $this->makeUserWithRole('Employee', [
            'department' => 'Finance',
            'employee_id' => 'EMP-302',
        ]);

        $procurementForm = $this->procurementForm();
        $passwordResetForm = $this->passwordResetForm();

        foreach (range(1, 17) as $index) {
            $this->createSubmission(
                $employee,
                $procurementForm,
                $index % 2 === 0 ? 'pending_it' : 'completed',
                [
                    'item_name' => "Laptop {$index}",
                ],
            );
        }

        $targetSubmission = $this->createSubmission(
            $employee,
            $passwordResetForm,
            'rejected',
            [
                'username' => 'employee.portal',
            ],
        );

        $this->createSubmission(
            $otherEmployee,
            $passwordResetForm,
            'completed',
            [
                'username' => 'other.portal',
            ],
        );

        $this->actingAs($employee)
            ->getJson('/api/form-submissions?page=2')
            ->assertOk()
            ->assertJsonPath('pagination.current_page', 2)
            ->assertJsonPath('pagination.total', 18)
            ->assertJsonPath('pagination.last_page', 2)
            ->assertJsonCount(3, 'submissions');

        $this->actingAs($employee)
            ->getJson('/api/form-submissions?search=password')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonCount(1, 'submissions')
            ->assertJsonPath('submissions.0.id', $targetSubmission->id)
            ->assertJsonPath('submissions.0.form.name', $passwordResetForm->name);
    }

    public function test_admin_can_search_submissions_by_requester_and_indonesian_status_label(): void
    {
        $admin = $this->makeUserWithRole('Admin');
        $alice = $this->makeUserWithRole('Employee', [
            'name' => 'Alice Procurement',
            'department' => 'General Affairs',
            'employee_id' => 'EMP-401',
        ]);
        $bob = $this->makeUserWithRole('Employee', [
            'name' => 'Bob Finance',
            'department' => 'Finance',
            'employee_id' => 'EMP-402',
        ]);

        $form = $this->procurementForm();

        $aliceSubmission = $this->createSubmission(
            $alice,
            $form,
            'completed',
            [
                'item_name' => 'Laptop Direksi',
            ],
        );

        $this->createSubmission(
            $bob,
            $form,
            'pending_director',
            [
                'item_name' => 'Printer Finance',
            ],
        );

        $this->actingAs($admin)
            ->getJson('/api/form-submissions?search=Alice')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonCount(1, 'submissions')
            ->assertJsonPath('submissions.0.id', $aliceSubmission->id)
            ->assertJsonPath('submissions.0.user.name', 'Alice Procurement');

        $this->actingAs($admin)
            ->getJson('/api/form-submissions?search=selesai')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonCount(1, 'submissions')
            ->assertJsonPath('submissions.0.id', $aliceSubmission->id)
            ->assertJsonPath('submissions.0.current_status', 'completed');
    }

    private function procurementForm(): Form
    {
        return Form::query()->where('slug', 'hardware-software-procurement')->firstOrFail();
    }

    private function passwordResetForm(): Form
    {
        return Form::query()->where('slug', 'password-reset-request')->firstOrFail();
    }

    private function makeUserWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    private function createSubmission(User $user, Form $form, string $status, array $formData = []): FormSubmission
    {
        return FormSubmission::query()->create([
            'form_id' => $form->id,
            'user_id' => $user->id,
            'form_data' => $formData,
            'form_snapshot' => $form->form_config,
            'current_status' => $status,
            'current_step' => 1,
            'created_by' => $user->id,
        ]);
    }
}

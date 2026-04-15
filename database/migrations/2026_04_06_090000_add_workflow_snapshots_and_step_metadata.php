<?php

use App\Models\FormSubmission;
use App\Support\WorkflowConfigService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('form_submissions', function (Blueprint $table) {
            $table->json('workflow_snapshot')->nullable()->after('form_snapshot');
        });

        Schema::table('approval_steps', function (Blueprint $table) {
            $table->string('step_key')->nullable()->after('step_number');
            $table->string('actor_type')->nullable()->after('approver_role');
            $table->string('actor_value')->nullable()->after('actor_type');
            $table->string('actor_label')->nullable()->after('actor_value');
            $table->json('config_snapshot')->nullable()->after('signature_id');
        });

        $workflowConfigService = app(WorkflowConfigService::class);

        FormSubmission::query()
            ->with(['form.workflow', 'approvalSteps'])
            ->chunkById(50, function ($submissions) use ($workflowConfigService) {
                /** @var FormSubmission $submission */
                foreach ($submissions as $submission) {
                    $workflowConfig = $workflowConfigService->normalize(
                        (array) ($submission->form?->workflow?->workflow_config ?? [])
                    );

                    if (($submission->workflow_snapshot ?? null) === null && ($workflowConfig['steps'] ?? []) !== []) {
                        $submission->forceFill([
                            'workflow_snapshot' => $workflowConfig,
                        ])->save();
                    }

                    foreach ($submission->approvalSteps as $approvalStep) {
                        $stepConfig = collect($workflowConfig['steps'] ?? [])
                            ->firstWhere('step_number', (int) $approvalStep->step_number);

                        if (!$stepConfig) {
                            continue;
                        }

                        $approvalStep->forceFill([
                            'step_key' => $stepConfig['step_key'],
                            'actor_type' => $stepConfig['actor_type'],
                            'actor_value' => $stepConfig['actor_value'],
                            'actor_label' => $stepConfig['actor_label'],
                            'config_snapshot' => $stepConfig,
                            'approver_role' => $approvalStep->approver_role ?: $stepConfig['actor_label'],
                        ])->save();
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('approval_steps', function (Blueprint $table) {
            $table->dropColumn([
                'step_key',
                'actor_type',
                'actor_value',
                'actor_label',
                'config_snapshot',
            ]);
        });

        Schema::table('form_submissions', function (Blueprint $table) {
            $table->dropColumn('workflow_snapshot');
        });
    }
};

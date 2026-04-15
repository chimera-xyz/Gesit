<?php

use App\Models\Workflow;
use App\Support\WorkflowConfigService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $workflowConfigService = app(WorkflowConfigService::class);

        Workflow::query()
            ->chunkById(50, function ($workflows) use ($workflowConfigService) {
                /** @var Workflow $workflow */
                foreach ($workflows as $workflow) {
                    $workflow->forceFill([
                        'workflow_config' => $workflowConfigService->normalizeForStorage(
                            (array) ($workflow->workflow_config ?? [])
                        ),
                    ])->save();
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally left blank. Workflow configs stay on the newer schema.
    }
};

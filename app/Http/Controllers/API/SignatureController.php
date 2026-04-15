<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ApprovalStep;
use App\Models\Signature;
use App\Support\SignatureImageProcessor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SignatureController extends Controller
{
    public function __construct(
        private readonly SignatureImageProcessor $signatureImageProcessor,
    ) {
    }

    /**
     * Save drawn signature without approving the step yet.
     */
    public function draw(Request $request)
    {
        try {
            $validated = $request->validate([
                'signature_data' => 'required|string',
                'approval_step_id' => 'required|exists:approval_steps,id',
            ]);

            $approvalStep = $this->validateApprovalStepOwnership($validated['approval_step_id']);
            $path = $this->storeBase64Signature($validated['signature_data']);

            $signature = Signature::create([
                'user_id' => auth()->id(),
                'signature_image' => $path,
                'signature_type' => 'draw',
                'metadata' => [
                    'approval_step_id' => $approvalStep->id,
                    'submitted_via' => 'draw',
                ],
                'verified' => true,
                'signed_at' => now(),
            ]);

            $signature->generateHash();

            return response()->json([
                'success' => true,
                'signature' => $signature,
                'signature_url' => $this->publicSignatureUrl($path),
            ]);
        } catch (\Exception $e) {
            Log::error('Signature Draw Error: ' . $e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Upload signature image without approving the step yet.
     */
    public function upload(Request $request)
    {
        try {
            $validated = $request->validate([
                'signature' => 'required|image|max:5120|mimes:png,jpg,jpeg',
                'approval_step_id' => 'required|exists:approval_steps,id',
            ]);

            $approvalStep = $this->validateApprovalStepOwnership($validated['approval_step_id']);
            $file = $request->file('signature');
            $path = $this->signatureImageProcessor->storeNormalizedBinary(
                (string) file_get_contents($file->getRealPath()),
                (int) auth()->id()
            );

            $signature = Signature::create([
                'user_id' => auth()->id(),
                'signature_image' => $path,
                'signature_type' => 'upload',
                'metadata' => [
                    'approval_step_id' => $approvalStep->id,
                    'submitted_via' => 'upload',
                ],
                'verified' => true,
                'signed_at' => now(),
            ]);

            $signature->generateHash();

            return response()->json([
                'success' => true,
                'signature' => $signature,
                'signature_url' => $this->publicSignatureUrl($path),
            ]);
        } catch (\Exception $e) {
            Log::error('Signature Upload Error: ' . $e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function verify($id)
    {
        try {
            $signature = Signature::findOrFail($id);

            return response()->json([
                'valid' => $signature->verifyHash($signature->signature_hash),
                'verified' => $signature->verified,
                'created_at' => $signature->created_at,
            ]);
        } catch (\Exception $e) {
            Log::error('Signature Verification Error: ' . $e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function userSignatures()
    {
        try {
            $signatures = Signature::with('approvalStep.formSubmission')
                ->where('user_id', auth()->id())
                ->latest()
                ->get();

            return response()->json([
                'signatures' => $signatures,
            ]);
        } catch (\Exception $e) {
            Log::error('Get User Signatures Error: ' . $e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $signature = Signature::findOrFail($id);

            if ($signature->user_id !== auth()->id()) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            Storage::disk('public')->delete($signature->signature_image);
            $signature->delete();

            return response()->json([
                'success' => true,
                'message' => 'Signature deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Signature Delete Error: ' . $e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function validateApprovalStepOwnership(int $approvalStepId): ApprovalStep
    {
        $approvalStep = ApprovalStep::findOrFail($approvalStepId);
        $user = auth()->user();
        $actorType = $approvalStep->actor_type ?? ($approvalStep->config_snapshot['actor_type'] ?? null);
        $actorValue = $approvalStep->actor_value ?? ($approvalStep->config_snapshot['actor_value'] ?? null);

        if (!$user->hasRole('Admin')) {
            $authorized = match ($actorType) {
                'user' => (int) $actorValue === (int) $user->id,
                'role' => $actorValue !== null && $user->hasRole($actorValue),
                default => $approvalStep->approver_role !== null && $user->hasRole($approvalStep->approver_role),
            };

            if (!$authorized) {
                abort(403, 'Unauthorized');
            }
        }

        if ($approvalStep->status !== 'pending') {
            abort(422, 'Approval step is not pending');
        }

        return $approvalStep;
    }

    private function storeBase64Signature(string $signatureData): string
    {
        if (!preg_match('/^data:image\/(\w+);base64,(.+)$/', $signatureData, $matches)) {
            throw new \RuntimeException('Invalid signature data');
        }

        $binary = base64_decode($matches[2], true);

        if ($binary === false) {
            throw new \RuntimeException('Invalid signature payload');
        }

        return $this->signatureImageProcessor->storeNormalizedBinary($binary, (int) auth()->id());
    }

    private function publicSignatureUrl(string $path): string
    {
        return url("/storage/{$path}");
    }
}

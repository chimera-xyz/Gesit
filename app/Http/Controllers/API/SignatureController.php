<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Signature;
use App\Models\ApprovalStep;
use App\Models\FormSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SignatureController extends Controller
{
    /**
     * Create digital signature (draw)
     */
    public function draw(Request $request)
    {
        try {
            $validated = $request->validate([
                'signature_data' => 'required|string',
                'approval_step_id' => 'required|exists:approval_steps,id',
            ]);

            $signatureImage = $this->processDrawnSignature($validated['signature_data']);

            // Create signature record
            $signature = new Signature([
                'user_id' => auth()->id(),
                'approval_step_id' => $validated['approval_step_id'],
                'signature_image' => $signatureImage,
                'signature_type' => 'draw',
                'signed_at' => now(),
            ]);

            // Generate verification hash
            $signature->generateHash();

            // Save signature
            $signature->save();

            // Update approval step with signature
            $approvalStep = ApprovalStep::findOrFail($validated['approval_step_id']);
            $approvalStep->signature_id = $signature->id;
            $approvalStep->status = 'approved';
            $approvalStep->approved_at = now();
            $approvalStep->save();

            return response()->json([
                'success' => true,
                'signature' => $signature,
                'signature_url' => url($signatureImage),
            ]);
        } catch (\Exception $e) {
            Log::error('Signature Draw Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Upload digital signature (image file)
     */
    public function upload(Request $request)
    {
        try {
            $validated = $request->validate([
                'signature' => 'required|image|max:5120|mimes:png,jpg,jpeg',
                'approval_step_id' => 'required|exists:approval_steps,id',
            ]);

            // Process and store uploaded signature
            $signatureImage = $this->processUploadedSignature($request->file('signature'));

            // Create signature record
            $signature = new Signature([
                'user_id' => auth()->id(),
                'approval_step_id' => $validated['approval_step_id'],
                'signature_image' => $signatureImage,
                'signature_type' => 'upload',
                'signed_at' => now(),
            ]);

            // Generate verification hash
            $signature->generateHash();

            // Save signature
            $signature->save();

            // Update approval step with signature
            $approvalStep = ApprovalStep::findOrFail($validated['approval_step_id']);
            $approvalStep->signature_id = $signature->id;
            $approvalStep->status = 'approved';
            $approvalStep->approved_at = now();
            $approvalStep->save();

            return response()->json([
                'success' => true,
                'signature' => $signature,
                'signature_url' => url($signatureImage),
            ]);
        } catch (\Exception $e) {
            Log::error('Signature Upload Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Verify signature authenticity
     */
    public function verify($id)
    {
        try {
            $signature = Signature::findOrFail($id);

            // Verify hash integrity
            $isValid = $signature->verifyHash($signature->signature_hash);

            // Check signature age (should be within 24 hours for security)
            $signatureAge = now()->diffInHours($signature->created_at);
            $isRecent = $signatureAge <= 24;

            return response()->json([
                'valid' => $isValid && $isRecent,
                'verified' => $signature->verified,
                'created_at' => $signature->created_at,
                'signature_age_hours' => $signatureAge,
            ]);
        } catch (\Exception $e) {
            Log::error('Signature Verification Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get user signatures
     */
    public function userSignatures()
    {
        try {
            $signatures = Signature::with('approvalStep.formSubmission')
                ->where('user_id', auth()->id())
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'signatures' => $signatures,
            ]);
        } catch (\Exception $e) {
            Log::error('Get User Signatures Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete signature
     */
    public function destroy($id)
    {
        try {
            $signature = Signature::findOrFail($id);

            // Check ownership
            if ($signature->user_id !== auth()->id()) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            // Delete image file
            if ($signature->signature_image && Storage::exists($signature->signature_image)) {
                Storage::delete($signature->signature_image);
            }

            // Delete signature record
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

    /**
     * Process drawn signature from canvas data
     */
    private function processDrawnSignature($signatureData)
    {
        // Decode base64 signature data
        $imageData = explode(',', $signatureData);
        $imageData = explode(';', $imageData[1]);

        // Create image from base64 data
        $image = imagecreatefromstring($imageData[1]);

        if (!$image) {
            throw new \Exception('Invalid signature data');
        }

        // Add transparency background
        imagealphablending($image, true);

        // Remove white background (common in canvas signatures)
        $white = imagecolorallocate($image, 255, 255, 255);
        imagecolortransparent($image, $white);

        // Resize signature for better quality
        $signatureResized = imagescale($image, 500, 200, true);

        // Generate watermark
        $watermarked = $this->addWatermark($signatureResized);

        // Save signature with timestamp and verification data
        $filename = 'signature_' . auth()->id() . '_' . now()->format('Y-m-d_His') . '.png';
        $path = "signatures/{$filename}";

        Storage::put($path, $watermarked);

        return $path;
    }

    /**
     * Process uploaded signature image
     */
    private function processUploadedSignature($uploadedFile)
    {
        // Move uploaded file to storage
        $filename = 'signature_' . auth()->id() . '_' . now()->format('Y-m-d_His') . '.' . $uploadedFile->getClientOriginalExtension();
        $path = "signatures/{$filename}";

        $uploadedFile->storeAs('signatures', $filename, 'public');

        // Add watermark to uploaded signature
        $watermarked = $this->addWatermark(storage_path("app/public/{$path}"));

        return $path;
    }

    /**
     * Add watermark to signature for authenticity verification
     */
    private function addWatermark($imagePath)
    {
        $image = imagecreatefrompng($imagePath);

        // Load company watermark
        $watermarkPath = public_path('images/watermark.png');
        if (!file_exists($watermarkPath)) {
            // Create simple watermark text
            $watermark = imagecreatetruecolor(400, 300, '#808080');
            $textColor = imagecolorallocate($watermark, 255, 255, 255);
            $black = imagecolorallocate($watermark, 0, 0, 0);
            imagefilledellipse($watermark, 200, 150, 0, 0, 0, $black);

            // Add company name and timestamp
            $text = 'Yuli Sekuritas Indonesia - ' . now()->format('d/m/Y H:i');
            imagettftext($watermark, 5, 0, $textColor, 'Inter.ttf', 8, 0, $text, $black);

            // Save watermark
            imagepng($watermark, $watermarkPath);
            $watermarkPath = $watermark;
        }

        // Add watermark to signature
        $watermark = imagecreatefrompng($watermarkPath);
        $watermarkSize = getimagesize($watermark);

        // Calculate watermark position (bottom right)
        $destX = imagesx($image) - $watermarkSize[0] - 20;
        $destY = imagesy($image) - $watermarkSize[1] - 20;

        // Merge watermark with signature
        imagecopymerge($watermark, $image, $destX, $destY, 0, 50);

        // Save watermarked signature
        $filename = 'watermarked_' . basename($imagePath);
        $outputPath = "signatures/{$filename}";

        imagepng($image, $outputPath);

        imagedestroy($image);
        imagedestroy($watermark);

        return $outputPath;
    }
}
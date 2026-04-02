<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SignatureImageProcessor
{
    public function storeNormalizedBinary(string $binary, int $userId): string
    {
        $normalized = $this->normalizeBinary($binary);
        $filename = 'signature_' . $userId . '_' . now()->format('YmdHis') . '_' . Str::random(6) . '.png';
        $path = "signatures/{$filename}";

        Storage::disk('public')->put($path, $normalized);

        return $path;
    }

    public function normalizedAbsolutePath(?string $absolutePath): ?string
    {
        if (!$absolutePath || !file_exists($absolutePath)) {
            return null;
        }

        $cacheDirectory = storage_path('app/private/signature-cache');

        if (!is_dir($cacheDirectory)) {
            mkdir($cacheDirectory, 0755, true);
        }

        $cachePath = $cacheDirectory . '/' . sha1_file($absolutePath) . '.png';

        if (!file_exists($cachePath) || filemtime($cachePath) < filemtime($absolutePath)) {
            $normalized = $this->normalizeBinary((string) file_get_contents($absolutePath));
            file_put_contents($cachePath, $normalized);
        }

        return $cachePath;
    }

    private function normalizeBinary(string $binary): string
    {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('GD extension is required for signature normalization.');
        }

        $source = @imagecreatefromstring($binary);

        if ($source === false) {
            throw new \RuntimeException('Invalid signature image payload.');
        }

        try {
            $cropped = $this->cropToInkBounds($source);

            ob_start();
            imagepng($cropped);
            $normalized = (string) ob_get_clean();
            imagedestroy($cropped);

            return $normalized;
        } finally {
            imagedestroy($source);
        }
    }

    private function cropToInkBounds(\GdImage $source): \GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $minX = $width;
        $minY = $height;
        $maxX = -1;
        $maxY = -1;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if ($this->isInkPixel($source, $x, $y)) {
                    $minX = min($minX, $x);
                    $minY = min($minY, $y);
                    $maxX = max($maxX, $x);
                    $maxY = max($maxY, $y);
                }
            }
        }

        if ($maxX < 0 || $maxY < 0) {
            return $this->createTransparentCanvas(320, 120);
        }

        $padding = 12;
        $cropX = max(0, $minX - $padding);
        $cropY = max(0, $minY - $padding);
        $cropWidth = min($width - $cropX, ($maxX - $minX + 1) + ($padding * 2));
        $cropHeight = min($height - $cropY, ($maxY - $minY + 1) + ($padding * 2));

        $target = $this->createTransparentCanvas($cropWidth, $cropHeight);

        for ($y = 0; $y < $cropHeight; $y++) {
            for ($x = 0; $x < $cropWidth; $x++) {
                $rgba = imagecolorat($source, $cropX + $x, $cropY + $y);
                $alpha = ($rgba >> 24) & 0x7F;
                $red = ($rgba >> 16) & 0xFF;
                $green = ($rgba >> 8) & 0xFF;
                $blue = $rgba & 0xFF;

                if ($this->looksLikeBackground($red, $green, $blue, $alpha)) {
                    $alpha = 127;
                    $red = 255;
                    $green = 255;
                    $blue = 255;
                }

                $color = imagecolorallocatealpha($target, $red, $green, $blue, $alpha);
                imagesetpixel($target, $x, $y, $color);
            }
        }

        return $target;
    }

    private function createTransparentCanvas(int $width, int $height): \GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $transparent);

        return $canvas;
    }

    private function isInkPixel(\GdImage $image, int $x, int $y): bool
    {
        $rgba = imagecolorat($image, $x, $y);
        $alpha = ($rgba >> 24) & 0x7F;
        $red = ($rgba >> 16) & 0xFF;
        $green = ($rgba >> 8) & 0xFF;
        $blue = $rgba & 0xFF;

        return !$this->looksLikeBackground($red, $green, $blue, $alpha);
    }

    private function looksLikeBackground(int $red, int $green, int $blue, int $alpha): bool
    {
        if ($alpha >= 120) {
            return true;
        }

        return $red >= 245 && $green >= 245 && $blue >= 245;
    }
}

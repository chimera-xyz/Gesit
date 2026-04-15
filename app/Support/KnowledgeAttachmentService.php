<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class KnowledgeAttachmentService
{
    private const MAX_TEXT_LENGTH = 40000;

    public function store(UploadedFile $file, ?string $existingPath = null): array
    {
        if ($existingPath) {
            Storage::disk('public')->delete($existingPath);
        }

        $path = $file->store('knowledge-hub', 'public');

        return [
            'attachment_path' => $path,
            'attachment_name' => $file->getClientOriginalName(),
            'attachment_mime' => $file->getClientMimeType(),
            'attachment_size' => $file->getSize(),
            'attachment_text' => $this->extractText($file),
        ];
    }

    public function clear(?string $path = null): array
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }

        return [
            'attachment_path' => null,
            'attachment_name' => null,
            'attachment_mime' => null,
            'attachment_size' => null,
            'attachment_text' => null,
        ];
    }

    private function extractText(UploadedFile $file): ?string
    {
        $realPath = $file->getRealPath();

        if (! $realPath || ! is_file($realPath)) {
            return null;
        }

        $extension = Str::lower($file->getClientOriginalExtension());
        $mime = Str::lower((string) $file->getClientMimeType());

        try {
            $rawText = match (true) {
                in_array($extension, ['txt', 'md', 'csv', 'log'], true), Str::startsWith($mime, 'text/')
                    => file_get_contents($realPath) ?: null,
                $extension === 'pdf', $mime === 'application/pdf'
                    => $this->runShellCommand(sprintf('%s -layout -nopgbrk %%s -', $this->resolveBinary([
                        '/opt/homebrew/bin/pdftotext',
                        'pdftotext',
                    ])), $realPath),
                in_array($extension, ['doc', 'docx', 'rtf'], true),
                    in_array($mime, [
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'application/rtf',
                        'text/rtf',
                    ], true)
                    => $this->runShellCommand(sprintf('%s -convert txt -stdout %%s', $this->resolveBinary([
                        '/usr/bin/textutil',
                        'textutil',
                    ])), $realPath),
                default => null,
            };
        } catch (\Throwable $exception) {
            Log::warning('Knowledge attachment text extraction failed', [
                'filename' => $file->getClientOriginalName(),
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        return $this->normalizeText($rawText);
    }

    private function runShellCommand(string $pattern, string $path): ?string
    {
        $command = sprintf($pattern, escapeshellarg($path)).' 2>/dev/null';
        $output = shell_exec($command);

        return is_string($output) ? $output : null;
    }

    private function resolveBinary(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if ($candidate[0] === '/' && is_file($candidate)) {
                return escapeshellcmd($candidate);
            }

            if ($candidate[0] !== '/') {
                return escapeshellcmd($candidate);
            }
        }

        return escapeshellcmd($candidates[0]);
    }

    private function normalizeText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $normalized = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $text);
        $normalized = preg_replace("/[ \t]+/u", ' ', $normalized) ?? $normalized;
        $normalized = preg_replace("/\n{3,}/u", "\n\n", $normalized) ?? $normalized;
        $normalized = trim($normalized);

        if ($normalized === '') {
            return null;
        }

        return Str::limit($normalized, self::MAX_TEXT_LENGTH, '...');
    }
}

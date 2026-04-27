<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\MobileAppRelease;
use App\Support\AndroidApkMetadataReader;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MobileAppReleaseController extends Controller
{
    private const SUPPORTED_PLATFORMS = ['android'];

    public function index()
    {
        try {
            $releases = MobileAppRelease::query()
                ->with('uploadedBy:id,name,email')
                ->orderByDesc('version_code')
                ->orderByDesc('id')
                ->get()
                ->map(fn (MobileAppRelease $release) => $this->transformRelease($release))
                ->values();

            $latestPublished = MobileAppRelease::query()
                ->published()
                ->where('platform', 'android')
                ->where('channel', 'production')
                ->orderByDesc('version_code')
                ->first();

            $highestVersionCode = MobileAppRelease::query()->max('version_code');

            return response()->json([
                'releases' => $releases,
                'meta' => [
                    'platforms' => self::SUPPORTED_PLATFORMS,
                    'channels' => ['production'],
                    'latest_published_version_code' => $latestPublished?->version_code,
                    'latest_published_version_name' => $latestPublished?->version_name,
                    'next_version_code_suggestion' => ((int) ($highestVersionCode ?? 0)) + 1,
                    'minimum_supported_version_code_suggestion' => (int) ($latestPublished?->version_code ?? 1),
                ],
            ]);
        } catch (ValidationException|HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('List Mobile App Releases Error: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json(['error' => 'Gagal memuat release mobile app.'], 500);
        }
    }

    public function store(Request $request)
    {
        $storedPath = null;

        try {
            $validated = $this->validateReleasePayload($request);
            $file = $request->file('apk_file');
            if (! $file instanceof UploadedFile) {
                throw new HttpResponseException(response()->json([
                    'error' => 'File APK wajib diunggah.',
                ], 422));
            }

            $filePayload = $this->storeReleaseFile(
                file: $file,
                platform: $validated['platform'],
                channel: $validated['channel'],
                versionCode: (int) $validated['version_code'],
            );
            $storedPath = $filePayload['apk_path'];

            /** @var MobileAppRelease $release */
            $release = DB::transaction(function () use ($validated, $filePayload, $request) {
                return MobileAppRelease::query()->create([
                    'platform' => $validated['platform'],
                    'channel' => $validated['channel'],
                    'version_name' => $validated['version_name'],
                    'version_code' => (int) $validated['version_code'],
                    'minimum_supported_version_code' => (int) $validated['minimum_supported_version_code'],
                    'is_force_update' => (bool) ($validated['force_update'] ?? false),
                    'release_notes' => $this->nullableTrim($validated['release_notes'] ?? null),
                    'apk_path' => $filePayload['apk_path'],
                    'apk_file_name' => $filePayload['apk_file_name'],
                    'apk_mime_type' => $filePayload['apk_mime_type'],
                    'file_size' => $filePayload['file_size'],
                    'sha256' => $filePayload['sha256'],
                    'is_published' => $validated['publish_now'] ?? false,
                    'published_at' => ($validated['publish_now'] ?? false) ? now() : null,
                    'uploaded_by' => $request->user()?->id,
                ]);
            });

            $release->load('uploadedBy:id,name,email');

            return response()->json([
                'success' => true,
                'release' => $this->transformRelease($release),
            ], 201);
        } catch (ValidationException|HttpResponseException $e) {
            if (is_string($storedPath) && $storedPath !== '') {
                Storage::disk('local')->delete($storedPath);
            }

            throw $e;
        } catch (\Throwable $e) {
            if (is_string($storedPath) && $storedPath !== '') {
                Storage::disk('local')->delete($storedPath);
            }

            Log::error('Create Mobile App Release Error: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json(['error' => 'Gagal membuat release mobile app.'], 500);
        }
    }

    public function update(Request $request, int $id)
    {
        $newStoredPath = null;

        try {
            /** @var MobileAppRelease $release */
            $release = MobileAppRelease::query()->findOrFail($id);
            $validated = $this->validateReleasePayload($request, $release);

            $oldStoredPath = $release->apk_path;
            $filePayload = null;

            $file = $request->file('apk_file');
            if ($file instanceof UploadedFile) {
                $filePayload = $this->storeReleaseFile(
                    file: $file,
                    platform: $validated['platform'],
                    channel: $validated['channel'],
                    versionCode: (int) $validated['version_code'],
                );
                $newStoredPath = $filePayload['apk_path'];
            }

            DB::transaction(function () use ($release, $validated, $filePayload) {
                $publishNow = $validated['publish_now'] ?? $release->is_published;
                $publishedAt = $publishNow
                    ? ($release->published_at ?? now())
                    : null;

                $release->fill([
                    'platform' => $validated['platform'],
                    'channel' => $validated['channel'],
                    'version_name' => $validated['version_name'],
                    'version_code' => (int) $validated['version_code'],
                    'minimum_supported_version_code' => (int) $validated['minimum_supported_version_code'],
                    'is_force_update' => (bool) ($validated['force_update'] ?? false),
                    'release_notes' => $this->nullableTrim($validated['release_notes'] ?? null),
                    'is_published' => $publishNow,
                    'published_at' => $publishedAt,
                ]);

                if (is_array($filePayload)) {
                    $release->fill([
                        'apk_path' => $filePayload['apk_path'],
                        'apk_file_name' => $filePayload['apk_file_name'],
                        'apk_mime_type' => $filePayload['apk_mime_type'],
                        'file_size' => $filePayload['file_size'],
                        'sha256' => $filePayload['sha256'],
                    ]);
                }

                $release->save();
            });

            if (is_array($filePayload) && filled($oldStoredPath) && $oldStoredPath !== $newStoredPath) {
                Storage::disk('local')->delete($oldStoredPath);
            }

            $release->refresh()->load('uploadedBy:id,name,email');

            return response()->json([
                'success' => true,
                'release' => $this->transformRelease($release),
            ]);
        } catch (ValidationException|HttpResponseException $e) {
            if (is_string($newStoredPath) && $newStoredPath !== '') {
                Storage::disk('local')->delete($newStoredPath);
            }

            throw $e;
        } catch (\Throwable $e) {
            if (is_string($newStoredPath) && $newStoredPath !== '') {
                Storage::disk('local')->delete($newStoredPath);
            }

            Log::error('Update Mobile App Release Error: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json(['error' => 'Gagal memperbarui release mobile app.'], 500);
        }
    }

    public function publish(int $id)
    {
        try {
            /** @var MobileAppRelease $release */
            $release = MobileAppRelease::query()->findOrFail($id);
            $release->forceFill([
                'is_published' => true,
                'published_at' => now(),
            ])->save();

            $release->refresh()->load('uploadedBy:id,name,email');

            return response()->json([
                'success' => true,
                'release' => $this->transformRelease($release),
            ]);
        } catch (\Throwable $e) {
            Log::error('Publish Mobile App Release Error: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json(['error' => 'Gagal mempublikasikan release mobile app.'], 500);
        }
    }

    public function unpublish(int $id)
    {
        try {
            /** @var MobileAppRelease $release */
            $release = MobileAppRelease::query()->findOrFail($id);
            $release->forceFill([
                'is_published' => false,
                'published_at' => null,
            ])->save();

            $release->refresh()->load('uploadedBy:id,name,email');

            return response()->json([
                'success' => true,
                'release' => $this->transformRelease($release),
            ]);
        } catch (\Throwable $e) {
            Log::error('Unpublish Mobile App Release Error: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json(['error' => 'Gagal membatalkan publikasi release mobile app.'], 500);
        }
    }

    public function destroy(int $id)
    {
        try {
            /** @var MobileAppRelease $release */
            $release = MobileAppRelease::query()->findOrFail($id);
            $apkPath = $release->apk_path;
            $release->delete();

            if (filled($apkPath)) {
                Storage::disk('local')->delete($apkPath);
            }

            return response()->json([
                'success' => true,
                'message' => 'Release mobile app berhasil dihapus.',
            ]);
        } catch (\Throwable $e) {
            Log::error('Delete Mobile App Release Error: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json(['error' => 'Gagal menghapus release mobile app.'], 500);
        }
    }

    public function latest(Request $request)
    {
        try {
            $validated = $request->validate([
                'platform' => ['nullable', 'string'],
                'channel' => ['nullable', 'string', 'max:32'],
                'current_version_code' => ['nullable', 'integer', 'min:0'],
            ]);

            $platform = $this->normalizePlatform($validated['platform'] ?? 'android');
            $channel = $this->normalizeChannel($validated['channel'] ?? 'production');
            $currentVersionCode = (int) ($validated['current_version_code'] ?? 0);

            if (! in_array($platform, self::SUPPORTED_PLATFORMS, true)) {
                return response()->json([
                    'status' => 'unsupported_platform',
                    'release' => null,
                ], 422);
            }

            $release = MobileAppRelease::query()
                ->published()
                ->where('platform', $platform)
                ->where('channel', $channel)
                ->orderByDesc('version_code')
                ->orderByDesc('id')
                ->first();

            if (! $release instanceof MobileAppRelease) {
                return response()->json([
                    'status' => 'up_to_date',
                    'release' => null,
                ]);
            }

            $status = 'up_to_date';
            if ($currentVersionCode < $release->version_code) {
                $status = $release->is_force_update ? 'force_update' : 'optional_update';
            }

            return response()->json([
                'status' => $status,
                'release' => $this->transformPublicRelease($release, $request),
            ]);
        } catch (ValidationException|HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Latest Mobile App Release Error: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json(['error' => 'Gagal memeriksa pembaruan mobile app.'], 500);
        }
    }

    public function download(Request $request, int $id)
    {
        try {
            /** @var MobileAppRelease $release */
            $release = MobileAppRelease::query()->findOrFail($id);

            if (! $release->is_published) {
                abort(404);
            }

            if (! Storage::disk('local')->exists($release->apk_path)) {
                abort(404);
            }

            @set_time_limit(0);
            @ini_set('max_execution_time', '0');

            return response()->download(
                Storage::disk('local')->path($release->apk_path),
                $release->apk_file_name,
                [
                    'Content-Type' => $release->apk_mime_type ?: 'application/vnd.android.package-archive',
                    'X-Accel-Buffering' => 'no',
                ]
            );
        } catch (ValidationException|HttpResponseException $e) {
            throw $e;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Download Mobile App Release Error: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json(['error' => 'Gagal mengunduh file release mobile app.'], 500);
        }
    }

    private function validateReleasePayload(Request $request, ?MobileAppRelease $existing = null): array
    {
        $platform = $this->normalizePlatform($request->input('platform', $existing?->platform ?? 'android'));
        $channel = $this->normalizeChannel($request->input('channel', $existing?->channel ?? 'production'));
        $currentReleaseId = $existing?->id;
        $file = $request->file('apk_file');
        $apkMetadata = $file instanceof UploadedFile
            ? (new AndroidApkMetadataReader())->read($file->getPathname())
            : [];
        $forceUpdate = $request->has('force_update')
            ? $request->boolean('force_update')
            : (bool) ($existing?->is_force_update ?? false);
        $metadataVersionCode = (int) ($apkMetadata['version_code'] ?? 0);
        $metadataVersionName = $this->nullableTrim($apkMetadata['version_name'] ?? null);

        $request->merge([
            'platform' => $platform,
            'channel' => $channel,
            'force_update' => $forceUpdate,
        ]);

        if ($metadataVersionCode > 0) {
            $request->merge(['version_code' => $metadataVersionCode]);
        }

        if ($metadataVersionName !== null) {
            $request->merge(['version_name' => $metadataVersionName]);
        }

        $resolvedVersionCode = (int) ($request->input('version_code') ?: $existing?->version_code ?: 0);
        if ($forceUpdate && $resolvedVersionCode > 0) {
            $request->merge(['minimum_supported_version_code' => $resolvedVersionCode]);
        } elseif (! $request->filled('minimum_supported_version_code')) {
            $suggestedMinimum = $this->minimumSupportedVersionCodeSuggestion(
                platform: $platform,
                channel: $channel,
                versionCode: $resolvedVersionCode,
            );
            $request->merge(['minimum_supported_version_code' => $suggestedMinimum]);
        }

        $rules = [
            'platform' => ['required', 'string'],
            'channel' => ['required', 'string', 'max:32'],
            'version_name' => ['required', 'string', 'max:50'],
            'version_code' => [
                'required',
                'integer',
                'min:1',
                function (string $attribute, mixed $value, \Closure $fail) use ($platform, $channel, $currentReleaseId): void {
                    $exists = MobileAppRelease::query()
                        ->where('platform', $platform)
                        ->where('channel', $channel)
                        ->where('version_code', (int) $value)
                        ->when($currentReleaseId !== null, fn ($query) => $query->where('id', '!=', $currentReleaseId))
                        ->exists();

                    if ($exists) {
                        $fail('Version code sudah dipakai untuk platform dan channel ini.');
                    }
                },
            ],
            'minimum_supported_version_code' => ['required', 'integer', 'min:1', 'lte:version_code'],
            'force_update' => ['sometimes', 'boolean'],
            'release_notes' => ['nullable', 'string', 'max:5000'],
            'publish_now' => ['sometimes', 'boolean'],
            'apk_file' => [
                $existing ? 'sometimes' : 'required',
                'file',
                'max:262144',
                'extensions:apk',
                'mimetypes:application/vnd.android.package-archive,application/octet-stream',
            ],
        ];

        $validated = $request->validate($rules);
        $validated['platform'] = $platform;
        $validated['channel'] = $channel;
        $validated['force_update'] = $forceUpdate;

        if (! in_array($platform, self::SUPPORTED_PLATFORMS, true)) {
            throw new HttpResponseException(response()->json([
                'error' => 'Platform release belum didukung.',
            ], 422));
        }

        return $validated;
    }

    private function minimumSupportedVersionCodeSuggestion(
        string $platform,
        string $channel,
        int $versionCode,
    ): int {
        $latestPublishedVersionCode = (int) (MobileAppRelease::query()
            ->published()
            ->where('platform', $platform)
            ->where('channel', $channel)
            ->max('version_code') ?? 1);

        if ($versionCode <= 0) {
            return max(1, $latestPublishedVersionCode);
        }

        return max(1, min($latestPublishedVersionCode, $versionCode));
    }

    /**
     * @return array{apk_path:string,apk_file_name:string,apk_mime_type:string,file_size:int,sha256:string}
     */
    private function storeReleaseFile(
        UploadedFile $file,
        string $platform,
        string $channel,
        int $versionCode,
    ): array {
        $originalName = trim((string) $file->getClientOriginalName());
        $baseName = pathinfo($originalName !== '' ? $originalName : "gesit-{$versionCode}.apk", PATHINFO_FILENAME);
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if ($extension === '') {
            $extension = 'apk';
        }

        $safeBaseName = Str::slug($baseName);
        if ($safeBaseName === '') {
            $safeBaseName = 'gesit-release';
        }

        $storedName = sprintf(
            '%s-v%d-%s.%s',
            $safeBaseName,
            $versionCode,
            Str::lower(Str::random(8)),
            $extension
        );

        $directory = sprintf('mobile-app-releases/%s/%s', $platform, $channel);
        $storedPath = $directory.'/'.$storedName;
        $sourcePath = $this->readableUploadedFilePath($file);
        $sourceStream = $sourcePath !== null ? @fopen($sourcePath, 'rb') : false;
        $testingTempStream = null;
        $uploadedContents = null;

        try {
            if (is_resource($sourceStream)) {
                $stored = Storage::disk('local')->put($storedPath, $sourceStream);
            } else {
                $testingTempStream = $this->testingUploadedFileTempStream($file);

                if (is_resource($testingTempStream)) {
                    rewind($testingTempStream);
                    $stored = Storage::disk('local')->put($storedPath, $testingTempStream);
                    rewind($testingTempStream);
                } else {
                    $uploadedContents = $this->uploadedFileContents($file);
                    $stored = Storage::disk('local')->put($storedPath, $uploadedContents);
                }
            }
        } finally {
            if (is_resource($sourceStream)) {
                fclose($sourceStream);
            }
        }

        if (! $stored) {
            throw new \RuntimeException('File APK gagal disimpan.');
        }

        $sha256 = $this->uploadedFileSha256($sourcePath, $testingTempStream, $uploadedContents);
        if (! is_string($sha256) || $sha256 === '') {
            Storage::disk('local')->delete($storedPath);
            throw new \RuntimeException('Checksum APK gagal dihitung.');
        }

        return [
            'apk_path' => $storedPath,
            'apk_file_name' => $originalName !== '' ? $originalName : $storedName,
            'apk_mime_type' => $file->getMimeType() ?: 'application/vnd.android.package-archive',
            'file_size' => (int) (Storage::disk('local')->size($storedPath) ?: $file->getSize() ?: 0),
            'sha256' => $sha256,
        ];
    }

    private function readableUploadedFilePath(UploadedFile $file): ?string
    {
        $path = $file->getPathname();
        if (is_string($path) && $path !== '' && is_file($path) && is_readable($path)) {
            return $path;
        }

        return null;
    }

    /**
     * @return resource|null
     */
    private function testingUploadedFileTempStream(UploadedFile $file): mixed
    {
        if (! property_exists($file, 'tempFile')) {
            return null;
        }

        $stream = $file->tempFile;

        return is_resource($stream) ? $stream : null;
    }

    private function uploadedFileContents(UploadedFile $file): string
    {
        $contents = $file->get();
        if (! is_string($contents)) {
            throw new \RuntimeException('File APK gagal dibaca.');
        }

        return $contents;
    }

    /**
     * @param resource|null $testingTempStream
     */
    private function uploadedFileSha256(?string $sourcePath, mixed $testingTempStream, ?string $uploadedContents): string
    {
        if ($sourcePath !== null) {
            $sha256 = hash_file('sha256', $sourcePath);

            return is_string($sha256) ? $sha256 : '';
        }

        if (is_resource($testingTempStream)) {
            rewind($testingTempStream);
            $context = hash_init('sha256');
            hash_update_stream($context, $testingTempStream);
            rewind($testingTempStream);

            return hash_final($context);
        }

        return hash('sha256', $uploadedContents ?? '');
    }

    private function transformRelease(MobileAppRelease $release): array
    {
        $release->loadMissing('uploadedBy:id,name,email');

        return [
            'id' => (int) $release->id,
            'platform' => $release->platform,
            'channel' => $release->channel,
            'version_name' => $release->version_name,
            'version_code' => (int) $release->version_code,
            'minimum_supported_version_code' => (int) $release->minimum_supported_version_code,
            'is_force_update' => (bool) $release->is_force_update,
            'release_notes' => $release->release_notes,
            'apk_file_name' => $release->apk_file_name,
            'apk_mime_type' => $release->apk_mime_type,
            'file_size' => (int) $release->file_size,
            'sha256' => $release->sha256,
            'is_published' => (bool) $release->is_published,
            'update_mode' => $release->is_force_update ? 'force' : 'optional',
            'published_at' => optional($release->published_at)?->toISOString(),
            'created_at' => optional($release->created_at)?->toISOString(),
            'updated_at' => optional($release->updated_at)?->toISOString(),
            'uploaded_by' => $release->uploadedBy ? [
                'id' => (int) $release->uploadedBy->id,
                'name' => $release->uploadedBy->name,
                'email' => $release->uploadedBy->email,
            ] : null,
        ];
    }

    private function transformPublicRelease(
        MobileAppRelease $release,
        Request $request,
    ): array {
        $downloadPath = URL::temporarySignedRoute(
            'api.mobile-app.releases.download',
            now()->addMinutes(30),
            ['id' => $release->id],
            absolute: false,
        );

        return [
            'id' => (int) $release->id,
            'platform' => $release->platform,
            'channel' => $release->channel,
            'version_name' => $release->version_name,
            'version_code' => (int) $release->version_code,
            'minimum_supported_version_code' => (int) $release->minimum_supported_version_code,
            'is_force_update' => (bool) $release->is_force_update,
            'update_mode' => $release->is_force_update ? 'force' : 'optional',
            'release_notes' => $release->release_notes,
            'apk_file_name' => $release->apk_file_name,
            'file_size' => (int) $release->file_size,
            'sha256' => $release->sha256,
            'download_path' => $downloadPath,
            'download_url' => rtrim($request->root(), '/').$downloadPath,
            'published_at' => optional($release->published_at)?->toISOString(),
        ];
    }

    private function normalizePlatform(string $platform): string
    {
        return Str::lower(trim($platform));
    }

    private function normalizeChannel(string $channel): string
    {
        $normalized = Str::lower(trim($channel));

        return $normalized === '' ? 'production' : $normalized;
    }

    private function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}

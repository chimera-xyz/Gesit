<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InventoryAssistantService
{
    public function __construct(private readonly PortalRegistry $portalRegistry) {}

    public function maybeAnswer(User $user, string $question, array $conversationHistory = []): ?array
    {
        $normalized = $this->normalize($question);

        if (! $this->isInventoryIntent($normalized, $conversationHistory)) {
            return null;
        }

        if (! $this->portalRegistry->canAccess($user, 'inventaris')) {
            return [
                'scope' => 'inventory',
                'answer' => 'Saya paham Anda sedang menanyakan data Inventaris IT, tetapi akun ini belum diberi akses ke aplikasi Inventaris IT di portal.',
                'sources' => [],
                'provider' => 'inventory',
            ];
        }

        $baseUrl = rtrim((string) config('services.inventory.base_url'), '/');
        $secret = (string) config('services.inventory.assistant_secret');
        $timeout = (int) config('services.inventory.timeout', 12);

        if ($baseUrl === '' || $secret === '') {
            return [
                'scope' => 'inventory',
                'answer' => 'Integrasi live ke Inventaris IT belum dikonfigurasi penuh, jadi saya belum bisa mengecek data asset secara real-time saat ini.',
                'sources' => [],
                'provider' => 'inventory',
            ];
        }

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->withHeaders([
                    'X-Gesit-Assistant-Secret' => $secret,
                ])
                ->post($baseUrl . '/api/internal/assistant/inventory/query', [
                    'question' => trim($question),
                    'limit' => 4,
                ]);

            if ($response->failed()) {
                Log::warning('Inventory assistant request failed', [
                    'status' => $response->status(),
                    'body' => Str::limit($response->body(), 500),
                ]);

                return [
                    'scope' => 'inventory',
                    'answer' => 'Saya mencoba cek Inventaris IT, tetapi koneksi ke data asset sedang bermasalah. Coba beberapa saat lagi.',
                    'sources' => [],
                    'provider' => 'inventory',
                ];
            }

            $payload = $response->json();

            return $this->formatResponse($payload, trim($question));
        } catch (\Throwable $exception) {
            Log::warning('Inventory assistant request exception', [
                'message' => $exception->getMessage(),
            ]);

            return [
                'scope' => 'inventory',
                'answer' => 'Saya belum berhasil menjangkau Inventaris IT untuk sekarang. Coba ulangi lagi sebentar.',
                'sources' => [],
                'provider' => 'inventory',
            ];
        }
    }

    private function formatResponse(array $payload, string $question): array
    {
        $summary = Arr::get($payload, 'summary', []);
        $items = collect(Arr::get($payload, 'items', []))
            ->filter(fn ($item) => is_array($item))
            ->values();

        if ($items->isEmpty()) {
            $searchLabel = trim((string) Arr::get($summary, 'search_label', 'asset yang Anda cari'));
            $statusLabel = trim((string) Arr::get($summary, 'requested_status_label', ''));
            $availabilityLine = $statusLabel !== ''
                ? " dengan status {$statusLabel}"
                : '';

            return [
                'scope' => 'inventory',
                'answer' => "Saya sudah cek Inventaris IT, tetapi belum menemukan {$searchLabel}{$availabilityLine} yang cocok saat ini.",
                'sources' => [],
                'provider' => 'inventory',
            ];
        }

        $matchedTotal = (int) Arr::get($summary, 'matched_total', $items->count());
        $availableCount = (int) Arr::get($summary, 'available_count', 0);
        $inUseCount = (int) Arr::get($summary, 'in_use_count', 0);
        $maintenanceCount = (int) Arr::get($summary, 'maintenance_count', 0);
        $brokenCount = (int) Arr::get($summary, 'broken_count', 0);
        $lostCount = (int) Arr::get($summary, 'lost_count', 0);
        $locations = collect(Arr::get($summary, 'locations', []))
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->unique()
            ->values();
        $searchLabel = trim((string) Arr::get($summary, 'search_label', $question));

        $segments = [];

        if ($availableCount > 0) {
            $segments[] = "{$availableCount} tersedia";
        }

        if ($inUseCount > 0) {
            $segments[] = "{$inUseCount} sedang dipakai";
        }

        if ($maintenanceCount > 0) {
            $segments[] = "{$maintenanceCount} maintenance";
        }

        if ($brokenCount > 0) {
            $segments[] = "{$brokenCount} rusak";
        }

        if ($lostCount > 0) {
            $segments[] = "{$lostCount} hilang";
        }

        $distribution = $segments !== []
            ? implode(', ', $segments)
            : 'status detailnya belum tersedia';
        $locationText = $locations->isNotEmpty()
            ? ' Lokasi yang paling relevan: ' . $locations->take(3)->implode(', ') . '.'
            : '';

        $opening = "Saya cek Inventaris IT untuk {$searchLabel}. Ada {$matchedTotal} asset yang cocok, dengan komposisi {$distribution}.{$locationText}";
        $closing = $availableCount > 0
            ? 'Saya tampilkan unit yang paling relevan di bawah. Kalau mau, saya bisa lanjut fokus ke unit yang masih tersedia atau ringkas histori servisnya.'
            : 'Saya tampilkan unit yang paling relevan di bawah. Kalau mau, saya bisa lanjut bantu cari alternatif unit yang kondisinya paling aman.';

        return [
            'scope' => 'inventory',
            'answer' => "{$opening}\n\n[[DOCUMENT_CARDS]]\n\n{$closing}",
            'sources' => $items
                ->take(4)
                ->map(fn (array $item) => $this->transformItemToSource($item))
                ->all(),
            'provider' => 'inventory',
        ];
    }

    private function transformItemToSource(array $item): array
    {
        $titleParts = array_values(array_filter([
            trim((string) ($item['name'] ?? 'Asset')),
            filled($item['unique_code'] ?? null) ? '(' . trim((string) $item['unique_code']) . ')' : null,
        ]));
        $title = implode(' ', $titleParts);

        $detailSegments = array_values(array_filter([
            filled($item['location'] ?? null) ? 'Lokasi: ' . trim((string) $item['location']) : null,
            filled($item['assigned_user_name'] ?? null) ? 'Dipakai: ' . trim((string) $item['assigned_user_name']) : null,
            filled($item['assigned_division'] ?? null) ? 'Divisi: ' . trim((string) $item['assigned_division']) : null,
            $this->historySummary($item),
        ]));

        return [
            'id' => 'inventory-' . trim((string) ($item['unique_code'] ?? Str::random(8))),
            'title' => $title !== '' ? $title : 'Asset Inventaris IT',
            'path_label' => 'Inventaris IT',
            'space_name' => 'Inventaris IT',
            'type_label' => trim((string) ($item['category_name'] ?? 'Asset')),
            'version_label' => trim((string) ($item['status_label'] ?? Str::headline((string) ($item['status'] ?? 'asset')))),
            'summary' => $detailSegments !== []
                ? implode(' · ', $detailSegments)
                : 'Detail unit tersedia di aplikasi Inventaris IT.',
            'suggested_page' => null,
            'external_url' => $item['detail_url'] ?? $item['public_url'] ?? null,
            'source_kind' => 'inventory_item',
        ];
    }

    private function historySummary(array $item): ?string
    {
        $latestHistory = Arr::get($item, 'latest_history');
        $lastService = Arr::get($item, 'last_service');

        if (is_array($lastService) && filled($lastService['title'] ?? null)) {
            return 'Servis terakhir: ' . trim((string) $lastService['title']);
        }

        if (is_array($latestHistory) && filled($latestHistory['title'] ?? null)) {
            return 'Riwayat terbaru: ' . trim((string) $latestHistory['title']);
        }

        return null;
    }

    private function isInventoryIntent(string $normalizedQuestion, array $conversationHistory): bool
    {
        if ($normalizedQuestion === '') {
            return false;
        }

        if ($this->containsAny($normalizedQuestion, [
            'inventaris', 'inventory', 'asset', 'aset', 'monitor', 'laptop', 'printer', 'scanner',
            'komputer', 'pc', 'cpu', 'proyektor', 'projector', 'router', 'switch', 'modem',
            'gudang', 'lokasi asset', 'serial number', 'kode unik', 'garansi', 'maintenance',
            'servis', 'service', 'dipakai', 'tersedia', 'available', 'stok', 'unit kosong',
        ])) {
            return true;
        }

        return $this->isInventoryFollowUp($normalizedQuestion, $conversationHistory);
    }

    private function isInventoryFollowUp(string $normalizedQuestion, array $conversationHistory): bool
    {
        $hasRecentInventoryContext = collect($conversationHistory)
            ->take(-6)
            ->contains(function (array $message) {
                return ($message['scope'] ?? null) === 'inventory'
                    || ($message['provider'] ?? null) === 'inventory';
            });

        if (! $hasRecentInventoryContext) {
            return false;
        }

        return $this->containsAny($normalizedQuestion, [
            'yang itu', 'yang tadi', 'lokasinya', 'unitnya', 'kondisinya', 'servisnya',
            'garansinya', 'yang tersedia', 'yang paling bagus', 'yg itu', 'yg tadi',
        ]) || count(explode(' ', $normalizedQuestion)) <= 6;
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        $normalized = Str::lower(Str::ascii($value));
        $normalized = preg_replace('/[^a-z0-9\+\-]+/u', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);
    }
}

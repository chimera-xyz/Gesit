<?php

namespace App\Support;

use App\Models\KnowledgeEntry;
use App\Models\KnowledgeSpace;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class KnowledgeAssistantService
{
    private const MAX_SOURCE_COUNT = 4;
    private const MIN_SOURCE_SCORE = 24;

    public function answer(User $user, string $question, array $conversationHistory = []): array
    {
        $normalizedQuestion = trim($question);
        $intent = $this->detectIntent($normalizedQuestion, $conversationHistory);

        if ($intent['type'] === 'conversation') {
            $answer = $this->generateConversationAnswer($normalizedQuestion, $conversationHistory);

            return [
                'scope' => 'conversation',
                'answer' => $answer['content'],
                'sources' => [],
                'provider' => $answer['provider'],
            ];
        }

        if ($intent['type'] === 'out_of_scope') {
            $answer = $this->generateOutOfScopeAnswer($normalizedQuestion, $conversationHistory);

            return [
                'scope' => 'conversation',
                'answer' => $answer['content'],
                'sources' => [],
                'provider' => $answer['provider'],
            ];
        }

        $retrievalQuestion = $this->buildRetrievalQuestion($normalizedQuestion, $conversationHistory);
        $globalContext = $this->getGlobalContextSpace();
        $divisionContexts = $this->searchDivisionContexts($retrievalQuestion);
        $internalResults = $this->searchEntries($user, 'internal', $retrievalQuestion);
        $domainResults = $this->searchEntries($user, 'securities_domain', $retrievalQuestion);
        $scope = $intent['scope'] ?: $this->classifyScope($retrievalQuestion, $internalResults, $domainResults, $divisionContexts);

        if ($scope === 'out_of_scope') {
            $scope = 'internal';
        }

        $results = $scope === 'internal' ? $internalResults : $domainResults;
        $sources = $this->selectSources($results, $retrievalQuestion);
        $contextSpaces = $this->resolveRelevantContexts($scope, $globalContext, $divisionContexts, collect($sources));

        $answer = $this->generateAnswer($normalizedQuestion, $scope, $sources, $conversationHistory, $contextSpaces);

        return [
            'scope' => $scope,
            'answer' => $answer['content'],
            'sources' => $sources,
            'provider' => $answer['provider'],
        ];
    }

    private function detectIntent(string $question, array $conversationHistory): array
    {
        $normalized = $this->normalizeIntentText($question);
        $scope = $this->inferScopeFromText($normalized);

        if ($normalized === '') {
            return [
                'type' => 'conversation',
                'scope' => null,
            ];
        }

        if ($this->hasKnowledgeMarker($normalized) || $scope !== null || $this->isKnowledgeFollowUp($normalized, $conversationHistory)) {
            return [
                'type' => 'knowledge',
                'scope' => $scope,
            ];
        }

        if ($this->isBasicConversation($normalized)) {
            return [
                'type' => 'conversation',
                'scope' => null,
            ];
        }

        return [
            'type' => 'out_of_scope',
            'scope' => null,
        ];
    }

    private function normalizeIntentText(string $text): string
    {
        $normalized = Str::lower(Str::ascii($text));
        $normalized = preg_replace('/[^a-z0-9\+\-]+/u', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);
    }

    private function isBasicConversation(string $normalizedQuestion): bool
    {
        $words = $normalizedQuestion === '' ? [] : explode(' ', $normalizedQuestion);
        $conversationPhrases = [
            'halo', 'hai', 'hi', 'hello', 'pagi', 'siang', 'sore', 'malam', 'selamat pagi',
            'selamat siang', 'selamat sore', 'selamat malam', 'test', 'tes', 'testing', 'ping',
            'siapa kamu', 'kamu siapa', 'anda siapa', 'lu siapa', 'lo siapa', 'elo siapa',
            'bisa bantu apa', 'kamu bisa apa', 'ini ai apa', 'ini bot apa', 'perkenalkan diri',
        ];

        if ($this->containsAny($normalizedQuestion, $conversationPhrases)) {
            return true;
        }

        $casualWords = ['halo', 'hai', 'hi', 'hello', 'test', 'tes', 'testing', 'ping', 'ok', 'oke'];
        $nonNumericWords = array_values(array_filter($words, fn ($word) => ! ctype_digit($word)));

        return count($words) <= 3 && $nonNumericWords !== []
            && collect($nonNumericWords)->every(fn ($word) => in_array($word, $casualWords, true));
    }

    private function hasKnowledgeMarker(string $normalizedQuestion): bool
    {
        $markers = [
            'cara', 'gimana', 'bagaimana', 'panduan', 'sop', 'dokumen', 'document', 'file',
            'form', 'workflow', 'prosedur', 'proses', 'step', 'langkah', 'checklist', 'ceklist',
            'lupa', 'cari', 'carikan', 'tolong cari', 'dimana', 'di mana', 'butuh',
            'housekeeping', 'house keeping', 'closing', 'reimburse', 'approval', 'helpdesk',
            'onboarding', 'jobdesk', 'notulen', 'policy', 'kebijakan', 'mkbd', 'rtbo',
            'kliring', 'settlement', 'sekuritas', 'pasar modal', 'saham', 'obligasi',
            'reksa dana', 'broker', 'pialang', 'kustodian', 'ojk', 'bei', 'idx',
        ];

        return $this->containsAny($normalizedQuestion, $markers);
    }

    private function inferScopeFromText(string $normalizedQuestion): ?string
    {
        $internalKeywords = [
            'sop', 'jobdesk', 'workflow', 'approval', 'cuti', 'reimburse', 'helpdesk', 'onboarding',
            'dokumen', 'panduan', 'aplikasi', 'divisi', 'form', 'proses', 'wi', 'notulen',
            'housekeeping', 'house keeping', 'closing', 'policy', 'kebijakan',
        ];

        if ($this->containsAny($normalizedQuestion, $internalKeywords)) {
            return 'internal';
        }

        $domainKeywords = [
            'sekuritas', 'pasar modal', 'saham', 'obligasi', 'reksa dana', 'broker', 'pialang',
            'ojk', 'bei', 'idx', 'mkbd', 'rtbo', 'kliring', 'settlement', 'margin', 'kustodian',
        ];

        if ($this->containsAny($normalizedQuestion, $domainKeywords)) {
            return 'securities_domain';
        }

        return null;
    }

    private function isKnowledgeFollowUp(string $normalizedQuestion, array $conversationHistory): bool
    {
        if (! $this->hasRecentKnowledgeTurn($conversationHistory)) {
            return false;
        }

        if ($this->startsNewTopic($normalizedQuestion)) {
            return false;
        }

        $followUpMarkers = [
            'itu', 'tadi', 'dokumen', 'dokumennya', 'file', 'filenya', 'sumber', 'sumbernya',
            'owner', 'pemilik', 'versi', 'halaman', 'detail', 'lanjut', 'jelasin', 'ringkas',
        ];

        return $this->containsAny($normalizedQuestion, $followUpMarkers);
    }

    private function startsNewTopic(string $normalizedQuestion): bool
    {
        $newTopicMarkers = [
            'gimana cara', 'bagaimana cara', 'cara import', 'cara export', 'cara upload',
            'cara download', 'cara login', 'cara reset', 'kenapa', 'mengapa', 'tolong cari',
            'carikan', 'sop ', 'panduan ',
        ];

        return $this->containsAny($normalizedQuestion, $newTopicMarkers);
    }

    private function hasRecentKnowledgeTurn(array $conversationHistory): bool
    {
        return collect($conversationHistory)
            ->take(-6)
            ->contains(function (array $message) {
                $scope = (string) ($message['scope'] ?? '');
                $sources = $message['sources'] ?? [];

                return in_array($scope, ['internal', 'securities_domain'], true)
                    || (is_array($sources) && $sources !== []);
            });
    }

    private function selectSources(Collection $results, string $question): array
    {
        $candidates = $results
            ->filter(fn (array $source) => (int) ($source['score'] ?? 0) >= self::MIN_SOURCE_SCORE)
            ->values();

        if ($candidates->isEmpty()) {
            return [];
        }

        $preferredSpaces = $this->preferredSpaceNamesFromQuestion($question);

        if ($preferredSpaces !== []) {
            $spaceMatched = $candidates
                ->filter(fn (array $source) => $this->sourceMatchesPreferredSpace($source, $preferredSpaces))
                ->values();

            if ($spaceMatched->isNotEmpty()) {
                $candidates = $spaceMatched;
            }
        }

        $bestScore = (int) ($candidates->first()['score'] ?? 0);
        $minimumScore = max(self::MIN_SOURCE_SCORE, (int) floor($bestScore * 0.58));

        return $candidates
            ->filter(fn (array $source) => (int) ($source['score'] ?? 0) >= $minimumScore)
            ->take(self::MAX_SOURCE_COUNT)
            ->values()
            ->all();
    }

    private function preferredSpaceNamesFromQuestion(string $question): array
    {
        $normalized = $this->normalizeIntentText($question);

        if ($normalized === '') {
            return [];
        }

        if ($this->containsSearchToken($normalized, 'it') || Str::contains($normalized, 'teknologi informasi')) {
            return ['it', 'teknologi informasi'];
        }

        if ($this->containsSearchToken($normalized, 'finance') || Str::contains($normalized, 'keuangan')) {
            return ['finance', 'keuangan'];
        }

        if ($this->containsSearchToken($normalized, 'accounting') || Str::contains($normalized, 'akuntansi')) {
            return ['accounting', 'akuntansi'];
        }

        if ($this->containsSearchToken($normalized, 'sales') || Str::contains($normalized, 'marketing')) {
            return ['sales', 'marketing'];
        }

        return [];
    }

    private function sourceMatchesPreferredSpace(array $source, array $preferredSpaces): bool
    {
        $spaceName = $this->normalizeSearchText((string) ($source['space_name'] ?? ''));

        if ($spaceName === '') {
            return false;
        }

        foreach ($preferredSpaces as $preferredSpace) {
            $normalizedPreferred = $this->normalizeSearchText($preferredSpace);

            if (
                $spaceName === $normalizedPreferred
                || $this->containsSearchToken($spaceName, $normalizedPreferred)
                || (strlen($normalizedPreferred) > 3 && Str::contains($spaceName, $normalizedPreferred))
            ) {
                return true;
            }
        }

        return false;
    }

    private function searchEntries(User $user, string $scope, string $question): Collection
    {
        $entries = KnowledgeEntry::query()
            ->with(['section.space', 'roles'])
            ->visibleTo($user)
            ->whereHas('section.space', fn ($query) => $query->where('kind', 'division'))
            ->where('scope', $scope)
            ->get();

        $tokens = $this->tokenize($question);

        return $entries
            ->map(function (KnowledgeEntry $entry) use ($question, $tokens) {
                $score = $this->scoreEntry($entry, $question, $tokens);

                if ($score <= 0) {
                    return null;
                }

                return $this->transformSource($entry, $score, $tokens);
            })
            ->filter()
            ->sortByDesc('score')
            ->values();
    }

    private function classifyScope(
        string $question,
        Collection $internalResults,
        Collection $domainResults,
        Collection $divisionContexts
    ): string
    {
        $normalizedQuestion = Str::lower($question);
        $internalKeywords = [
            'sop', 'jobdesk', 'workflow', 'approval', 'cuti', 'reimburse', 'helpdesk', 'onboarding',
            'dokumen', 'panduan', 'aplikasi', 'divisi', 'form', 'proses', 'wi', 'notulen',
        ];
        $domainKeywords = [
            'sekuritas', 'pasar modal', 'saham', 'obligasi', 'reksa dana', 'broker', 'pialang', 'ojk',
            'bei', 'idx', 'mkbd', 'rtbo', 'kliring', 'settlement', 'margin', 'kustodian',
        ];

        $bestInternalScore = data_get($internalResults->first(), 'score', 0);
        $bestDomainScore = data_get($domainResults->first(), 'score', 0);
        $bestDivisionContextScore = (int) $divisionContexts->max('score');

        if ($bestInternalScore >= 100) {
            return 'internal';
        }

        if ($bestDomainScore >= 100 && $bestDomainScore >= $bestInternalScore) {
            return 'securities_domain';
        }

        if ($this->containsAny($normalizedQuestion, $internalKeywords)) {
            return 'internal';
        }

        if ($this->containsAny($normalizedQuestion, $domainKeywords)) {
            return 'securities_domain';
        }

        if ($bestInternalScore > 0) {
            return 'internal';
        }

        if ($bestDivisionContextScore > 0) {
            return 'internal';
        }

        if ($bestDomainScore > 0) {
            return 'securities_domain';
        }

        return 'out_of_scope';
    }

    private function generateConversationAnswer(string $question, array $conversationHistory = []): array
    {
        $conversationContext = $this->buildConversationContext($conversationHistory);
        $userPrompt = "Pesan pengguna:\n{$question}";

        if ($conversationContext !== null) {
            $userPrompt .= "\n\nRiwayat singkat:\n{$conversationContext}";
        }

        $result = $this->callChatCompletion([
            [
                'role' => 'system',
                'content' => implode("\n", [
                    'Kamu adalah AI Knowledge Assistant untuk GESIT di Yulie Sekuritas.',
                    'Jawab percakapan ringan, sapaan, tes chat, dan pertanyaan identitas secara natural, ramah, dan singkat dalam bahasa Indonesia.',
                    'Jangan menyebut evidence, scope, rule, atau sistem pencarian internal.',
                    'Kalau ditanya kemampuanmu, jelaskan bahwa kamu bisa membantu mencari SOP, panduan operasional, dokumen internal, workflow, dan konteks domain sekuritas di Knowledge Hub.',
                    'Jangan mengklaim sudah mencari dokumen jika pengguna belum meminta knowledge tertentu.',
                ]),
            ],
            [
                'role' => 'user',
                'content' => $userPrompt,
            ],
        ], 0.45);

        return $result ?: [
            'content' => $this->buildProviderUnavailableAnswer(),
            'provider' => 'fallback',
        ];
    }

    private function generateOutOfScopeAnswer(string $question, array $conversationHistory = []): array
    {
        $conversationContext = $this->buildConversationContext($conversationHistory);
        $userPrompt = "Pesan pengguna:\n{$question}";

        if ($conversationContext !== null) {
            $userPrompt .= "\n\nRiwayat singkat:\n{$conversationContext}";
        }

        $result = $this->callChatCompletion([
            [
                'role' => 'system',
                'content' => implode("\n", [
                    'Kamu adalah AI Knowledge Assistant untuk GESIT di Yulie Sekuritas.',
                    'Jawab seperti AI assistant umum yang ramah, praktis, dan aman dalam bahasa Indonesia.',
                    'Kamu boleh membantu pertanyaan umum, troubleshooting aplikasi, drafting, penjelasan konsep, dan produktivitas kerja.',
                    'Tolak atau arahkan dengan aman hanya untuk permintaan berbahaya, ilegal, pornografi eksplisit, kebencian/SARA, atau penyalahgunaan data.',
                    'Jika pertanyaan tampak terkait pekerjaan Yulie Sekuritas tapi tidak ada dokumen internal, berikan panduan umum yang aman dan jangan mengklaim itu SOP resmi.',
                    'Jangan menyebut evidence, scope, rule, atau sistem backend.',
                ]),
            ],
            [
                'role' => 'user',
                'content' => $userPrompt,
            ],
        ], 0.35);

        return $result ?: [
            'content' => $this->buildProviderUnavailableAnswer(),
            'provider' => 'fallback',
        ];
    }

    private function generateAnswer(
        string $question,
        string $scope,
        array $sources,
        array $conversationHistory = [],
        ?Collection $contextSpaces = null
    ): array
    {
        $contextSpaces = $contextSpaces ?? collect();

        $context = collect($sources)->map(function (array $source, int $index) {
            $position = $index + 1;

            return <<<TEXT
Sumber {$position}
Judul: {$source['title']}
Path: {$source['path_label']}
Divisi: {$source['space_name']}
Tipe: {$source['type_label']}
Versi: {$source['version_label']}
Owner: {$source['owner_name']}
Tanggal update: {$source['effective_date_label']}
Referensi: {$source['reference_notes']}
Tag: {$source['tags_label']}
Ringkasan: {$source['summary']}
Konten: {$source['content_excerpt']}
Halaman rekomendasi: {$source['suggested_page_label']}
TEXT;
        })->implode("\n\n---\n\n");

        $knowledgeContext = $contextSpaces
            ->map(function (array $space, int $index) {
                $position = $index + 1;
                $label = $space['kind'] === 'general' ? 'General Knowledge' : 'Knowledge Divisi';
                $instruction = trim((string) ($space['ai_instruction'] ?? ''));
                $knowledgeText = trim((string) ($space['knowledge_text'] ?? ''));

                return <<<TEXT
Konteks {$position}
Jenis: {$label}
Nama: {$space['name']}
Instruksi: {$instruction}
Knowledge: {$knowledgeText}
TEXT;
            })
            ->implode("\n\n---\n\n");

        $systemPrompt = $this->buildSystemPrompt($scope, $contextSpaces);
        $conversationContext = $this->buildConversationContext($conversationHistory);
        $userPrompt = "Pertanyaan pengguna:\n{$question}";

        if ($knowledgeContext !== '') {
            $userPrompt .= "\n\nGunakan knowledge setting berikut:\n{$knowledgeContext}";
        }

        if ($context !== '') {
            $userPrompt .= "\n\nGunakan dokumen berikut:\n{$context}";
        }

        if ($conversationContext !== null) {
            $userPrompt .= "\n\nRiwayat percakapan terkait:\n{$conversationContext}";
        }

        $userPrompt .= "\n\nInstruksi tambahan:\n- Jawab seperti asisten manusia yang sedang membantu user, bukan seperti template pencarian.\n- Jika dokumen ditemukan, struktur jawaban HARUS: kalimat pembuka singkat, baris persis [[DOCUMENT_CARDS]], lalu kalimat penutup/arah langkah berikutnya.\n- Jangan menulis ulang daftar dokumen dalam teks jika dokumen sudah diberikan, karena kartu dokumen akan ditampilkan otomatis di posisi [[DOCUMENT_CARDS]].\n- Jika dokumen tidak ditemukan, tetap bantu dengan panduan umum yang aman dan praktis. Jangan mengklaim panduan umum itu sebagai SOP resmi/internal.\n- Jika butuh data internal yang belum ada, minta detail tambahan secara natural sambil tetap memberi langkah awal yang bisa dicoba.\n- Jangan memakai kata \"evidence\", \"scope\", \"rule engine\", atau istilah teknis backend.\n- Jawab follow-up berdasarkan riwayat percakapan bila memang relevan.\n- Jika ada kartu dokumen terkait, tutup dengan kalimat singkat yang mengarahkan user membukanya untuk detail lengkap.";

        $result = $this->callChatCompletion([
            [
                'role' => 'system',
                'content' => $systemPrompt,
            ],
            [
                'role' => 'user',
                'content' => $userPrompt,
            ],
        ], 0.25);

        if ($result && $sources !== []) {
            $result['content'] = $this->ensureDocumentCardMarker($result['content'], $sources);
        }

        return $result ?: [
            'content' => $this->buildFallbackAnswer($sources, $contextSpaces, $question),
            'provider' => 'fallback',
        ];
    }

    private function ensureDocumentCardMarker(string $content, array $sources): string
    {
        if ($sources === [] || Str::contains($content, '[[DOCUMENT_CARDS]]')) {
            return $content;
        }

        $paragraphs = collect(preg_split("/\n{2,}/u", trim($content)) ?: [])
            ->map(fn ($paragraph) => trim((string) $paragraph))
            ->filter()
            ->values();

        if ($paragraphs->count() <= 1) {
            return trim($content)."\n\n[[DOCUMENT_CARDS]]\n\nBuka kartu dokumen terkait untuk melihat detail lengkapnya.";
        }

        return $paragraphs->first()
            ."\n\n[[DOCUMENT_CARDS]]\n\n"
            .$paragraphs->slice(1)->implode("\n\n");
    }

    private function callChatCompletion(array $messages, float $temperature): ?array
    {
        $apiKey = config('services.zai.api_key');
        $baseUrl = rtrim((string) config('services.zai.base_url', 'https://api.z.ai/api/paas/v4'), '/');
        $model = (string) config('services.zai.model', 'glm-5.1');
        $timeout = (int) config('services.zai.timeout', 30);

        if (! $apiKey) {
            return null;
        }

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->withToken($apiKey)
                ->withHeaders([
                    'Accept-Language' => 'id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7',
                ])
                ->post("{$baseUrl}/chat/completions", [
                    'model' => $model,
                    'temperature' => $temperature,
                    'stream' => false,
                    'messages' => $messages,
                ]);

            if ($response->failed()) {
                Log::warning('Z.AI knowledge assistant request failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return null;
            }

            $content = data_get($response->json(), 'choices.0.message.content');

            if (is_array($content)) {
                $content = collect($content)
                    ->map(fn ($item) => is_array($item) ? (string) ($item['text'] ?? '') : (string) $item)
                    ->implode("\n");
            }

            $content = trim((string) $content);

            if ($content === '') {
                return null;
            }

            return [
                'content' => $content,
                'provider' => 'z.ai',
            ];
        } catch (\Throwable $exception) {
            Log::warning('Z.AI knowledge assistant exception', [
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function buildProviderUnavailableAnswer(): string
    {
        return 'Maaf, AI sedang lambat merespons. Coba kirim ulang sebentar lagi.';
    }

    private function buildFallbackAnswer(array $sources, ?Collection $contextSpaces = null, string $question = ''): string
    {
        $contextSpaces = $contextSpaces ?? collect();

        if ($sources === []) {
            return $this->buildProviderUnavailableAnswer();
        }

        $primarySource = $sources[0];
        $lines = [
            'Saya menemukan dokumen yang paling relevan untuk pertanyaan ini.',
            '[[DOCUMENT_CARDS]]',
            'Mulai dari kartu dokumen tersebut, lalu ikuti bagian yang ditandai. '.($primarySource['suggested_page']
                ? "Saya arahkan ke halaman {$primarySource['suggested_page']} karena paling dekat dengan topik yang kamu tanyakan."
                : 'Kalau perlu detail lengkap, buka kartu dokumen terkait di atas.'),
        ];

        return implode("\n", $lines);
    }

    private function buildSystemPrompt(string $scope, Collection $contextSpaces): string
    {
        $basePrompt = $scope === 'internal'
            ? 'Anda adalah AI Knowledge Assistant GESIT untuk Yulie Sekuritas. Bantu user memahami SOP, workflow, dokumen, troubleshooting kerja, dan knowledge internal yang diberikan. Jawab natural, ramah, dan tetap akurat. Jika konteks dokumen tidak cukup, beri panduan umum yang aman tanpa mengklaimnya sebagai SOP resmi.'
            : 'Anda adalah AI Knowledge Assistant GESIT untuk Yulie Sekuritas. Bantu user memahami domain sekuritas berdasarkan konteks yang diberikan. Jawab natural, ramah, dan tetap akurat. Jika konteks dokumen tidak cukup, beri penjelasan umum yang aman tanpa mengklaimnya sebagai kebijakan resmi.';

        $instructionBlocks = $contextSpaces
            ->map(function (array $space) {
                $instruction = trim((string) ($space['ai_instruction'] ?? ''));

                if ($instruction === '') {
                    return null;
                }

                $label = $space['kind'] === 'general' ? 'Instruksi Global' : "Instruksi Divisi {$space['name']}";

                return "{$label}: {$instruction}";
            })
            ->filter()
            ->implode("\n");

        if ($instructionBlocks === '') {
            return $basePrompt;
        }

        return $basePrompt."\n\nIkuti instruksi tambahan berikut bila relevan:\n".$instructionBlocks;
    }

    private function scoreEntry(KnowledgeEntry $entry, string $question, array $tokens): int
    {
        $normalizedQuestion = $this->normalizeIntentText($question);
        $title = $this->normalizeSearchText($entry->title);
        $summary = $this->normalizeSearchText((string) $entry->summary);
        $content = $this->normalizeSearchText(strip_tags($this->entryContent($entry)));
        $tags = $this->normalizeSearchText(implode(' ', $entry->tags ?? []));
        $space = $this->normalizeSearchText((string) $entry->section?->space?->name);
        $section = $this->normalizeSearchText((string) ($entry->section?->is_default ? 'root' : $entry->section?->name));
        $type = $this->normalizeSearchText((string) $entry->type);

        $score = 0;
        $hasMeaningfulPhrase = $normalizedQuestion !== ''
            && ! $this->isLowSignalQuery($normalizedQuestion, $tokens)
            && strlen($normalizedQuestion) >= 4;

        if ($hasMeaningfulPhrase && Str::contains($title, $normalizedQuestion)) {
            $score += 120;
        }

        if ($hasMeaningfulPhrase && Str::contains($summary, $normalizedQuestion)) {
            $score += 80;
        }

        if ($hasMeaningfulPhrase && Str::contains($content, $normalizedQuestion)) {
            $score += 60;
        }

        foreach ($tokens as $token) {
            if ($this->containsSearchToken($title, $token)) {
                $score += 28;
            }

            if ($this->containsSearchToken($summary, $token)) {
                $score += 18;
            }

            if ($this->containsSearchToken($content, $token)) {
                $score += 12;
            }

            if ($this->containsSearchToken($tags, $token)) {
                $score += 16;
            }

            if ($this->containsSearchToken($space, $token) || $this->containsSearchToken($section, $token)) {
                $score += 10;
            }

            if ($this->containsSearchToken($type, $token)) {
                $score += 8;
            }
        }

        return $score;
    }

    private function tokenize(string $question): array
    {
        $normalizedQuestion = $this->normalizeIntentText($question);
        $tokens = preg_split('/[^a-zA-Z0-9\+\-]+/u', Str::lower(Str::ascii($question))) ?: [];

        if (Str::contains($normalizedQuestion, ['sop sore', 'rutin sore', 'operasional sore'])) {
            $tokens = array_merge($tokens, ['operasional', 'rutin', 'harian']);
        }

        $stopwords = [
            'yang', 'dan', 'untuk', 'dari', 'dengan', 'gimana', 'bagaimana', 'apa', 'itu', 'atau',
            'ke', 'di', 'pada', 'saya', 'kami', 'bisa', 'jadi', 'kalau', 'mohon', 'tolong',
            'gue', 'gua', 'aku', 'lu', 'lo', 'elo', 'anda', 'kamu', 'nih', 'dong', 'ya', 'yaa',
            'test', 'tes', 'testing', 'halo', 'hai', 'hello', 'hi', 'ping', 'siapa', 'coba',
            'cara', 'lupa', 'gitu', 'namanya', 'karna', 'karena', 'sayakan', 'divisi', 'tuh',
            'boleh', 'dibantu', 'bantu', 'minta', 'terkait',
        ];

        return collect($tokens)
            ->map(fn ($token) => trim((string) $token))
            ->map(function (string $token) {
                if (strlen($token) > 5 && Str::endsWith($token, 'nya')) {
                    return substr($token, 0, -3);
                }

                return $token;
            })
            ->filter(fn ($token) => strlen($token) >= 2 && ! ctype_digit($token) && !in_array($token, $stopwords, true))
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeSearchText(?string $text): string
    {
        $normalized = Str::lower(Str::ascii((string) $text));
        $normalized = preg_replace('/[^a-z0-9\+\-]+/u', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);
    }

    private function containsSearchToken(string $normalizedText, string $token): bool
    {
        $normalizedToken = $this->normalizeSearchText($token);

        if ($normalizedText === '' || $normalizedToken === '') {
            return false;
        }

        if (strlen($normalizedToken) <= 3) {
            return preg_match('/(?:^|\s)'.preg_quote($normalizedToken, '/').'(?:\s|$)/u', $normalizedText) === 1;
        }

        return Str::contains($normalizedText, $normalizedToken);
    }

    private function isLowSignalQuery(string $normalizedQuestion, array $tokens): bool
    {
        if ($tokens === []) {
            return true;
        }

        return (bool) preg_match('/^(test|tes|testing|halo|hai|hello|hi|ping|ok|oke|[0-9\s]+)$/', $normalizedQuestion);
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (Str::contains($haystack, Str::lower($needle))) {
                return true;
            }
        }

        return false;
    }

    private function buildRetrievalQuestion(string $question, array $conversationHistory): string
    {
        $normalizedQuestion = $this->normalizeIntentText($question);

        if ($this->startsNewTopic($normalizedQuestion)) {
            return $question;
        }

        if (! $this->isKnowledgeFollowUp($normalizedQuestion, $conversationHistory)) {
            return $question;
        }

        $recentUserTurns = collect($conversationHistory)
            ->where('role', 'user')
            ->take(-3)
            ->pluck('content')
            ->map(fn ($content) => trim((string) $content))
            ->filter()
            ->implode("\n");

        if ($recentUserTurns === '') {
            return $question;
        }

        return trim($recentUserTurns."\n".$question);
    }

    private function buildConversationContext(array $conversationHistory): ?string
    {
        $recentMessages = collect($conversationHistory)
            ->take(-6)
            ->map(function (array $message) {
                $role = $message['role'] === 'assistant' ? 'Assistant' : 'User';
                $content = trim((string) ($message['content'] ?? ''));

                if ($content === '') {
                    return null;
                }

                return "{$role}: {$content}";
            })
            ->filter()
            ->implode("\n");

        return $recentMessages !== '' ? $recentMessages : null;
    }

    private function getGlobalContextSpace(): ?array
    {
        $space = KnowledgeSpace::query()
            ->where('kind', 'general')
            ->where('is_active', true)
            ->first();

        if (! $space) {
            return null;
        }

        return $this->transformContextSpace($space, 0);
    }

    private function searchDivisionContexts(string $question): Collection
    {
        $tokens = $this->tokenize($question);

        return KnowledgeSpace::query()
            ->where('kind', 'division')
            ->where('is_active', true)
            ->get()
            ->map(function (KnowledgeSpace $space) use ($question, $tokens) {
                $score = $this->scoreContextSpace($space, $question, $tokens);

                if ($score <= 0) {
                    return null;
                }

                return $this->transformContextSpace($space, $score);
            })
            ->filter()
            ->sortByDesc('score')
            ->values();
    }

    private function scoreContextSpace(KnowledgeSpace $space, string $question, array $tokens): int
    {
        $normalizedQuestion = $this->normalizeIntentText($question);
        $name = Str::lower((string) $space->name);
        $description = Str::lower((string) $space->description);
        $instruction = Str::lower((string) $space->ai_instruction);
        $knowledgeText = Str::lower((string) $space->knowledge_text);
        $score = 0;
        $hasMeaningfulPhrase = $normalizedQuestion !== ''
            && ! $this->isLowSignalQuery($normalizedQuestion, $tokens)
            && strlen($normalizedQuestion) >= 4;

        if ($hasMeaningfulPhrase && Str::contains($name, $normalizedQuestion)) {
            $score += 110;
        }

        if ($hasMeaningfulPhrase && Str::contains($knowledgeText, $normalizedQuestion)) {
            $score += 80;
        }

        foreach ($tokens as $token) {
            if (Str::contains($name, $token)) {
                $score += 28;
            }

            if (Str::contains($description, $token)) {
                $score += 12;
            }

            if (Str::contains($instruction, $token)) {
                $score += 10;
            }

            if (Str::contains($knowledgeText, $token)) {
                $score += 14;
            }
        }

        return $score;
    }

    private function transformContextSpace(KnowledgeSpace $space, int $score): array
    {
        return [
            'id' => $space->id,
            'kind' => $space->kind,
            'name' => $space->name,
            'description' => $space->description,
            'ai_instruction' => $space->ai_instruction,
            'knowledge_text' => $space->knowledge_text,
            'score' => $score,
        ];
    }

    private function resolveRelevantContexts(
        string $scope,
        ?array $globalContext,
        Collection $divisionContexts,
        Collection $results
    ): Collection {
        $contexts = collect();

        if ($globalContext && $this->spaceHasContext($globalContext)) {
            $contexts->push($globalContext);
        }

        if ($scope === 'internal') {
            $contexts = $contexts->merge(
                $divisionContexts
                    ->filter(fn (array $space) => $this->spaceHasContext($space))
                    ->take(2)
                    ->values()
            );
        }

        $sourceSpaceIds = $results
            ->pluck('space_id')
            ->filter()
            ->unique()
            ->values();

        if ($sourceSpaceIds->isNotEmpty()) {
            $sourceSpaces = KnowledgeSpace::query()
                ->whereIn('id', $sourceSpaceIds)
                ->get()
                ->map(fn (KnowledgeSpace $space) => $this->transformContextSpace($space, 0))
                ->filter(fn (array $space) => $this->spaceHasContext($space))
                ->values();

            $contexts = $contexts->merge($sourceSpaces);
        }

        return $contexts
            ->unique('id')
            ->values();
    }

    private function spaceHasContext(array $space): bool
    {
        return trim((string) ($space['ai_instruction'] ?? '')) !== ''
            || trim((string) ($space['knowledge_text'] ?? '')) !== '';
    }

    private function transformSource(KnowledgeEntry $entry, int $score, array $tokens = []): array
    {
        $content = $this->entryContent($entry);
        $summary = trim((string) ($entry->summary ?: Str::limit(strip_tags($content), 160, '...')));
        $contentExcerpt = $this->contentExcerpt($content, $tokens);
        $attachmentUrl = $this->publicAttachmentUrl($entry->attachment_path);
        $sectionName = $entry->section?->is_default ? 'Root' : ($entry->section?->name ?? '-');
        $suggestedPage = $this->suggestedPage($entry->reference_notes, $content, $tokens);

        return [
            'id' => $entry->id,
            'title' => $entry->title,
            'summary' => $summary !== '' ? $summary : 'Ringkasan belum tersedia.',
            'content_excerpt' => $contentExcerpt !== '' ? $contentExcerpt : $summary,
            'scope' => $entry->scope,
            'type' => $entry->type,
            'type_label' => $this->typeLabel($entry->type),
            'space_id' => $entry->section?->space?->id,
            'space_name' => $entry->section?->space?->name ?? '-',
            'section_name' => $sectionName,
            'path_label' => trim(($entry->section?->space?->name ?? '-').' / '.$sectionName),
            'owner_name' => $entry->owner_name ?: '-',
            'version_label' => $entry->version_label ?: 'Belum diisi',
            'effective_date_label' => optional($entry->effective_date)?->format('d M Y') ?: 'Belum diisi',
            'reference_notes' => $entry->reference_notes ?: 'Halaman spesifik belum dicatat',
            'suggested_page' => $suggestedPage,
            'suggested_page_label' => $suggestedPage ? "Halaman {$suggestedPage}" : 'Belum ada halaman spesifik',
            'source_link' => $entry->source_link,
            'attachment_url' => $attachmentUrl,
            'tags' => $entry->tags ?? [],
            'tags_label' => $entry->tags ? implode(', ', $entry->tags) : '-',
            'score' => $score,
        ];
    }

    private function contentExcerpt(string $content, array $tokens): string
    {
        $plainContent = trim((string) preg_replace('/\s+/', ' ', strip_tags($content)));

        if ($plainContent === '') {
            return '';
        }

        $normalizedContent = Str::lower(Str::ascii($plainContent));
        $position = null;

        foreach ($tokens as $token) {
            $matchPosition = strpos($normalizedContent, $token);

            if ($matchPosition !== false) {
                $position = $matchPosition;
                break;
            }
        }

        if ($position === null || $position < 180) {
            return trim((string) Str::limit($plainContent, 720, '...'));
        }

        $excerpt = substr($plainContent, max(0, $position - 180), 900);

        return trim((string) Str::limit('...'.$excerpt, 720, '...'));
    }

    private function extractReferencePage(?string $referenceNotes): ?int
    {
        $normalized = trim((string) $referenceNotes);

        if ($normalized === '') {
            return null;
        }

        if (preg_match('/(?:halaman|page|hlm\.?|p\.)\s*(\d+)/i', $normalized, $matches)) {
            return (int) $matches[1] ?: null;
        }

        if (preg_match('/\b(\d{1,4})\b/', $normalized, $matches)) {
            return (int) $matches[1] ?: null;
        }

        return null;
    }

    private function suggestedPage(?string $referenceNotes, string $content, array $tokens): ?int
    {
        $explicitPage = $this->extractReferencePage($referenceNotes);

        if ($explicitPage) {
            return $explicitPage;
        }

        if ($tokens === [] || ! Str::contains($content, '[Halaman ')) {
            return null;
        }

        $parts = preg_split('/\[Halaman\s+(\d+)\]/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (! is_array($parts) || count($parts) < 3) {
            return null;
        }

        $bestPage = null;
        $bestScore = 0;

        for ($index = 1; $index < count($parts); $index += 2) {
            $page = (int) $parts[$index];
            $pageText = Str::lower(Str::ascii((string) ($parts[$index + 1] ?? '')));
            $score = 0;

            foreach ($tokens as $token) {
                if (Str::contains($pageText, $token)) {
                    $score++;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestPage = $page;
            }
        }

        return $bestScore > 0 ? $bestPage : null;
    }

    private function entryContent(KnowledgeEntry $entry): string
    {
        return collect([
            trim((string) $entry->body),
            trim((string) $entry->attachment_text),
        ])->filter()->implode("\n\n");
    }

    private function publicAttachmentUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return '/storage/'.ltrim($path, '/');
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'sop' => 'SOP',
            'troubleshooting' => 'Troubleshooting',
            'onboarding' => 'Onboarding',
            'form' => 'Form',
            'policy' => 'Kebijakan',
            'jobdesk' => 'Jobdesk',
            'faq' => 'FAQ',
            default => Str::headline($type),
        };
    }
}

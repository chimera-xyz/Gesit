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
    private const OUT_OF_SCOPE_MESSAGE = 'Maaf, saya hanya melayani pertanyaan terkait knowledge internal perusahaan dan domain sekuritas.';
    private const NO_DATA_MESSAGE = 'Data belum tersedia.';

    public function answer(User $user, string $question, array $conversationHistory = []): array
    {
        $normalizedQuestion = trim($question);
        $retrievalQuestion = $this->buildRetrievalQuestion($normalizedQuestion, $conversationHistory);
        $globalContext = $this->getGlobalContextSpace();
        $divisionContexts = $this->searchDivisionContexts($retrievalQuestion);
        $internalResults = $this->searchEntries($user, 'internal', $retrievalQuestion);
        $domainResults = $this->searchEntries($user, 'securities_domain', $retrievalQuestion);
        $scope = $this->classifyScope($retrievalQuestion, $internalResults, $domainResults, $divisionContexts);

        if ($scope === 'out_of_scope') {
            return [
                'scope' => 'out_of_scope',
                'answer' => self::OUT_OF_SCOPE_MESSAGE,
                'sources' => [],
                'provider' => 'rule-engine',
            ];
        }

        $results = $scope === 'internal' ? $internalResults : $domainResults;
        $contextSpaces = $this->resolveRelevantContexts($scope, $globalContext, $divisionContexts, $results);

        if ($results->isEmpty() && $contextSpaces->isEmpty()) {
            return [
                'scope' => $scope,
                'answer' => self::NO_DATA_MESSAGE,
                'sources' => [],
                'provider' => 'rule-engine',
            ];
        }

        $sources = $results->take(4)->values()->all();
        $answer = $this->generateAnswer($normalizedQuestion, $scope, $sources, $conversationHistory, $contextSpaces);

        return [
            'scope' => $scope,
            'answer' => $answer['content'],
            'sources' => $sources,
            'provider' => $answer['provider'],
        ];
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

                return $this->transformSource($entry, $score);
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

    private function generateAnswer(
        string $question,
        string $scope,
        array $sources,
        array $conversationHistory = [],
        ?Collection $contextSpaces = null
    ): array
    {
        $apiKey = config('services.zai.api_key');
        $baseUrl = rtrim((string) config('services.zai.base_url', 'https://api.z.ai/api/paas/v4'), '/');
        $model = (string) config('services.zai.model', 'glm-5.1');
        $timeout = (int) config('services.zai.timeout', 30);
        $contextSpaces = $contextSpaces ?? collect();

        if (!$apiKey) {
            return [
                'content' => $this->buildFallbackAnswer($sources, $contextSpaces),
                'provider' => 'fallback',
            ];
        }

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

        $userPrompt .= "\n\nInstruksi tambahan:\n- Jawab ringkas, jelas, dan step-by-step bila relevan.\n- Jawab follow-up berdasarkan riwayat percakapan bila memang relevan.\n- Jangan menjawab di luar konteks.\n- Di akhir jawaban, tambahkan kalimat singkat yang mengarahkan user untuk membuka sumber bila perlu detail lebih lanjut.";

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->withToken($apiKey)
                ->withHeaders([
                    'Accept-Language' => 'id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7',
                ])
                ->post("{$baseUrl}/chat/completions", [
                    'model' => $model,
                    'temperature' => 0.2,
                    'stream' => false,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $systemPrompt,
                        ],
                        [
                            'role' => 'user',
                            'content' => $userPrompt,
                        ],
                    ],
                ]);

            if ($response->failed()) {
                Log::warning('Z.AI knowledge assistant request failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return [
                    'content' => $this->buildFallbackAnswer($sources, $contextSpaces),
                    'provider' => 'fallback',
                ];
            }

            $content = data_get($response->json(), 'choices.0.message.content');

            if (is_array($content)) {
                $content = collect($content)
                    ->map(fn ($item) => is_array($item) ? (string) ($item['text'] ?? '') : (string) $item)
                    ->implode("\n");
            }

            $content = trim((string) $content);

            return [
                'content' => $content !== '' ? $content : self::NO_DATA_MESSAGE,
                'provider' => 'z.ai',
            ];
        } catch (\Throwable $exception) {
            Log::warning('Z.AI knowledge assistant exception', [
                'message' => $exception->getMessage(),
            ]);

            return [
                'content' => $this->buildFallbackAnswer($sources, $contextSpaces),
                'provider' => 'fallback',
            ];
        }
    }

    private function buildFallbackAnswer(array $sources, ?Collection $contextSpaces = null): string
    {
        $contextSpaces = $contextSpaces ?? collect();

        if ($sources === [] && $contextSpaces->isEmpty()) {
            return self::NO_DATA_MESSAGE;
        }

        if ($sources === []) {
            $lines = ['Saya menemukan knowledge yang paling relevan:'];

            foreach ($contextSpaces->take(2) as $index => $space) {
                $number = $index + 1;
                $excerpt = Str::limit(trim((string) ($space['knowledge_text'] ?? '')), 180, '...');
                $lines[] = "{$number}. {$space['name']} - ".($excerpt !== '' ? $excerpt : 'Knowledge text belum diisi.');
            }

            $lines[] = 'Jika masih perlu detail dokumen, buka Smart Document Hub untuk referensi pendukung.';

            return implode("\n", $lines);
        }

        $lines = ['Saya menemukan referensi yang paling relevan:'];

        foreach (array_slice($sources, 0, 3) as $index => $source) {
            $number = $index + 1;
            $lines[] = "{$number}. {$source['title']} - {$source['summary']}";
        }

        $lines[] = 'Buka dokumen sumber untuk detail lengkap dan versi terbaru.';

        return implode("\n", $lines);
    }

    private function buildSystemPrompt(string $scope, Collection $contextSpaces): string
    {
        $basePrompt = $scope === 'internal'
            ? 'Anda adalah GESIT Knowledge Assistant. Jawab hanya berdasarkan knowledge internal yang diberikan. Jangan mengarang. Jika konteks tidak cukup, balas tepat: "Data belum tersedia."'
            : 'Anda adalah GESIT Knowledge Assistant. Jawab hanya berdasarkan knowledge domain sekuritas yang diberikan. Jangan mengarang. Jika konteks tidak cukup, balas tepat: "Data belum tersedia."';

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
        $normalizedQuestion = Str::lower($question);
        $title = Str::lower($entry->title);
        $summary = Str::lower((string) $entry->summary);
        $content = Str::lower(strip_tags($this->entryContent($entry)));
        $tags = Str::lower(implode(' ', $entry->tags ?? []));
        $space = Str::lower((string) $entry->section?->space?->name);
        $section = Str::lower((string) ($entry->section?->is_default ? 'root' : $entry->section?->name));
        $type = Str::lower((string) $entry->type);

        $score = 0;

        if (Str::contains($title, $normalizedQuestion)) {
            $score += 120;
        }

        if (Str::contains($summary, $normalizedQuestion)) {
            $score += 80;
        }

        if (Str::contains($content, $normalizedQuestion)) {
            $score += 60;
        }

        foreach ($tokens as $token) {
            if (Str::contains($title, $token)) {
                $score += 28;
            }

            if (Str::contains($summary, $token)) {
                $score += 18;
            }

            if (Str::contains($content, $token)) {
                $score += 12;
            }

            if (Str::contains($tags, $token)) {
                $score += 16;
            }

            if (Str::contains($space, $token) || Str::contains($section, $token)) {
                $score += 10;
            }

            if (Str::contains($type, $token)) {
                $score += 8;
            }
        }

        return $score;
    }

    private function tokenize(string $question): array
    {
        $tokens = preg_split('/[^a-zA-Z0-9\+\-]+/u', Str::lower($question)) ?: [];
        $stopwords = [
            'yang', 'dan', 'untuk', 'dari', 'dengan', 'gimana', 'bagaimana', 'apa', 'itu', 'atau',
            'ke', 'di', 'pada', 'saya', 'kami', 'bisa', 'jadi', 'kalau', 'mohon', 'tolong',
        ];

        return collect($tokens)
            ->map(fn ($token) => trim((string) $token))
            ->filter(fn ($token) => strlen($token) >= 2 && !in_array($token, $stopwords, true))
            ->unique()
            ->values()
            ->all();
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
        $normalizedQuestion = Str::lower($question);
        $name = Str::lower((string) $space->name);
        $description = Str::lower((string) $space->description);
        $instruction = Str::lower((string) $space->ai_instruction);
        $knowledgeText = Str::lower((string) $space->knowledge_text);
        $score = 0;

        if (Str::contains($name, $normalizedQuestion)) {
            $score += 110;
        }

        if (Str::contains($knowledgeText, $normalizedQuestion)) {
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

    private function transformSource(KnowledgeEntry $entry, int $score): array
    {
        $content = $this->entryContent($entry);
        $summary = trim((string) ($entry->summary ?: Str::limit(strip_tags($content), 160, '...')));
        $contentExcerpt = trim((string) Str::limit(preg_replace('/\s+/', ' ', strip_tags($content)), 720, '...'));
        $attachmentUrl = $this->publicAttachmentUrl($entry->attachment_path);
        $sectionName = $entry->section?->is_default ? 'Root' : ($entry->section?->name ?? '-');

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
            'source_link' => $entry->source_link,
            'attachment_url' => $attachmentUrl,
            'tags' => $entry->tags ?? [],
            'tags_label' => $entry->tags ? implode(', ', $entry->tags) : '-',
            'score' => $score,
        ];
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

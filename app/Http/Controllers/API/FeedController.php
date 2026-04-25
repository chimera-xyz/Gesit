<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\FeedComment;
use App\Models\FeedPost;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FeedController extends Controller
{
    public function audienceMembers(Request $request)
    {
        try {
            $validated = $request->validate([
                'query' => ['nullable', 'string', 'max:100'],
            ]);

            /** @var User $user */
            $user = $request->user()->loadMissing('roles');
            $search = trim((string) ($validated['query'] ?? ''));

            $members = User::query()
                ->where('is_active', true)
                ->whereKeyNot($user->id)
                ->with('roles')
                ->when($search !== '', function ($query) use ($search) {
                    $query->where(function ($memberQuery) use ($search) {
                        $memberQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('department', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
                })
                ->orderBy('name')
                ->limit(300)
                ->get();

            return response()->json([
                'users' => $members
                    ->map(fn (User $member) => $this->transformAuthor($member))
                    ->values()
                    ->all(),
            ]);
        } catch (ValidationException|HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Feed Audience Members Error: '.$e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function index(Request $request)
    {
        try {
            $validated = $request->validate([
                'per_page' => ['nullable', 'integer', 'min:1', 'max:20'],
            ]);

            /** @var User $user */
            $user = $request->user()->loadMissing('roles');
            $perPage = min(max((int) ($validated['per_page'] ?? 10), 1), 20);

            $posts = FeedPost::query()
                ->visibleTo($user)
                ->withCount('recipients')
                ->with([
                    'author.roles',
                    'likes' => fn ($query) => $query->where('user_id', $user->id),
                ])
                ->orderByDesc('last_activity_at')
                ->orderByDesc('id')
                ->paginate($perPage);

            return response()->json([
                'posts' => collect($posts->items())
                    ->map(fn (FeedPost $post) => $this->transformPost($post, $user))
                    ->values()
                    ->all(),
                'pagination' => [
                    'current_page' => $posts->currentPage(),
                    'per_page' => $posts->perPage(),
                    'total' => $posts->total(),
                    'last_page' => $posts->lastPage(),
                ],
            ]);
        } catch (ValidationException|HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Feed Index Error: '.$e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function show(Request $request, FeedPost $post)
    {
        try {
            /** @var User $user */
            $user = $request->user()->loadMissing('roles');

            $thread = $this->loadThreadForUser($user, (int) $post->id);

            return response()->json([
                'post' => $this->transformPost($thread, $user),
            ]);
        } catch (ValidationException|HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Feed Show Error: '.$e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'content' => ['required', 'string', 'max:3000'],
                'visibility' => [
                    'required',
                    'string',
                    Rule::in([
                        FeedPost::VISIBILITY_PUBLIC,
                        FeedPost::VISIBILITY_DEPARTMENT,
                        FeedPost::VISIBILITY_PRIVATE,
                        FeedPost::VISIBILITY_SELECTED_USERS,
                    ]),
                ],
                'recipient_user_ids' => ['nullable', 'array', 'max:100'],
                'recipient_user_ids.*' => ['integer', Rule::exists('users', 'id')],
            ]);

            /** @var User $user */
            $user = $request->user()->loadMissing('roles');
            $content = $this->trimmedText($validated['content']);
            $visibility = $validated['visibility'];
            $department = trim((string) ($user->department ?? ''));
            $recipientUserIds = $this->normalizeRecipientUserIds(
                $validated['recipient_user_ids'] ?? [],
                $user,
            );

            if ($visibility === FeedPost::VISIBILITY_DEPARTMENT && $department === '') {
                throw new HttpResponseException(response()->json([
                    'error' => 'Posting untuk divisi membutuhkan data department pada akun Anda.',
                ], 422));
            }

            if ($visibility === FeedPost::VISIBILITY_SELECTED_USERS && $recipientUserIds === []) {
                throw new HttpResponseException(response()->json([
                    'error' => 'Posting untuk user tertentu membutuhkan minimal satu penerima.',
                ], 422));
            }

            $post = DB::transaction(function () use ($user, $content, $visibility, $department, $recipientUserIds) {
                $post = FeedPost::query()->create([
                    'user_id' => $user->id,
                    'visibility' => $visibility,
                    'target_department' => $visibility === FeedPost::VISIBILITY_DEPARTMENT ? $department : null,
                    'content' => $content,
                    'last_activity_at' => now(),
                ]);

                if ($visibility === FeedPost::VISIBILITY_SELECTED_USERS) {
                    $post->recipients()->sync($recipientUserIds);
                }

                return $post;
            });

            $this->dispatchPostNotifications($post, $user);

            $createdPost = $this->loadPostSummaryForUser($user, (int) $post->id);

            return response()->json([
                'success' => true,
                'post' => $this->transformPost($createdPost, $user),
            ], 201);
        } catch (ValidationException|HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Feed Store Error: '.$e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function destroy(Request $request, FeedPost $post)
    {
        try {
            /** @var User $user */
            $user = $request->user()->loadMissing('roles');

            $this->ensureCanDeletePost($user, $post);
            $postId = (string) $post->id;
            $post->delete();

            return response()->json([
                'success' => true,
                'deleted_post_id' => $postId,
            ]);
        } catch (ValidationException|HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Feed Destroy Error: '.$e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function togglePostLike(Request $request, FeedPost $post)
    {
        try {
            /** @var User $user */
            $user = $request->user()->loadMissing('roles');

            $this->ensureCanViewPost($user, $post);
            $liked = $this->toggleLike($post, $user);
            $updatedPost = $this->loadPostSummaryForUser($user, (int) $post->id);

            return response()->json([
                'success' => true,
                'liked' => $liked,
                'post' => $this->transformPost($updatedPost, $user),
            ]);
        } catch (ValidationException|HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Feed Toggle Post Like Error: '.$e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function storeComment(Request $request, FeedPost $post)
    {
        try {
            $validated = $request->validate([
                'content' => ['required', 'string', 'max:1500'],
                'parent_id' => ['nullable', 'integer', Rule::exists('feed_comments', 'id')],
                'mentioned_user_ids' => ['nullable', 'array', 'max:50'],
                'mentioned_user_ids.*' => ['integer', Rule::exists('users', 'id')],
            ]);

            /** @var User $user */
            $user = $request->user()->loadMissing('roles');
            $this->ensureCanViewPost($user, $post);

            $content = $this->trimmedText($validated['content']);
            $mentionedUserIds = $this->normalizeMentionedUserIds(
                $post,
                $content,
                $user,
                $validated['mentioned_user_ids'] ?? [],
            );
            $replyTarget = null;
            $rootComment = null;

            if (isset($validated['parent_id'])) {
                $replyTarget = FeedComment::query()
                    ->with('parent')
                    ->where('post_id', $post->id)
                    ->findOrFail($validated['parent_id']);

                $rootComment = $replyTarget->parent_id === null
                    ? $replyTarget
                    : $replyTarget->parent;
            }

            $comment = DB::transaction(function () use ($post, $user, $content, $replyTarget, $rootComment, $mentionedUserIds) {
                $comment = FeedComment::query()->create([
                    'post_id' => $post->id,
                    'user_id' => $user->id,
                    'parent_id' => $rootComment?->id,
                    'reply_to_comment_id' => $replyTarget?->id,
                    'reply_to_user_id' => $replyTarget?->user_id,
                    'content' => $content,
                ]);

                $post->increment('comment_count');
                $post->update(['last_activity_at' => $comment->created_at]);

                if ($rootComment !== null) {
                    $rootComment->increment('reply_count');
                }

                $this->dispatchCommentNotifications($post, $user, $replyTarget, $mentionedUserIds);

                return $comment;
            });

            $updatedPost = $this->loadPostSummaryForUser($user, (int) $post->id);
            $updatedComment = $this->loadCommentForUser($user, (int) $comment->id);

            return response()->json([
                'success' => true,
                'post' => $this->transformPost($updatedPost, $user),
                'comment' => $this->transformComment($updatedComment, $user),
            ], 201);
        } catch (ValidationException|HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Feed Comment Store Error: '.$e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function destroyComment(Request $request, FeedComment $comment)
    {
        try {
            /** @var User $user */
            $user = $request->user()->loadMissing('roles');
            $comment->loadMissing('post', 'replies');

            $this->ensureCanViewPost($user, $comment->post);
            $this->ensureCanDeleteComment($user, $comment);

            $postId = (int) $comment->post_id;
            $commentId = (string) $comment->id;
            $removedCommentCount = $comment->parent_id === null
                ? 1 + (int) $comment->reply_count
                : 1;

            DB::transaction(function () use ($comment, $postId, $removedCommentCount) {
                if ($comment->parent_id !== null) {
                    $rootComment = FeedComment::query()->lockForUpdate()->find($comment->parent_id);
                    if ($rootComment !== null && $rootComment->reply_count > 0) {
                        $rootComment->decrement('reply_count');
                    }
                }

                $post = FeedPost::query()->lockForUpdate()->findOrFail($postId);
                $newCommentCount = max(0, (int) $post->comment_count - $removedCommentCount);

                $comment->delete();

                $latestCommentAt = FeedComment::query()
                    ->where('post_id', $postId)
                    ->max('created_at');

                $post->forceFill([
                    'comment_count' => $newCommentCount,
                    'last_activity_at' => $latestCommentAt ?? $post->created_at,
                ])->save();
            });

            $updatedPost = $this->loadPostSummaryForUser($user, $postId);

            return response()->json([
                'success' => true,
                'deleted_comment_id' => $commentId,
                'post' => $this->transformPost($updatedPost, $user),
            ]);
        } catch (ValidationException|HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Feed Comment Destroy Error: '.$e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function toggleCommentLike(Request $request, FeedComment $comment)
    {
        try {
            /** @var User $user */
            $user = $request->user()->loadMissing('roles');
            $comment->loadMissing('post');

            $this->ensureCanViewPost($user, $comment->post);
            $liked = $this->toggleLike($comment, $user);
            $updatedComment = $this->loadCommentForUser($user, (int) $comment->id);

            return response()->json([
                'success' => true,
                'liked' => $liked,
                'comment' => $this->transformComment($updatedComment, $user),
            ]);
        } catch (ValidationException|HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Feed Toggle Comment Like Error: '.$e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function loadPostSummaryForUser(User $user, int $postId): FeedPost
    {
        return FeedPost::query()
            ->visibleTo($user)
            ->withCount('recipients')
            ->with([
                'author.roles',
                'likes' => fn ($query) => $query->where('user_id', $user->id),
            ])
            ->findOrFail($postId);
    }

    private function loadThreadForUser(User $user, int $postId): FeedPost
    {
        return FeedPost::query()
            ->visibleTo($user)
            ->withCount('recipients')
            ->with([
                'author.roles',
                'recipients.roles',
                'likes' => fn ($query) => $query->where('user_id', $user->id),
                'rootComments' => fn ($query) => $query
                    ->orderBy('created_at')
                    ->with([
                        'author.roles',
                        'replyToUser.roles',
                        'likes' => fn ($likeQuery) => $likeQuery->where('user_id', $user->id),
                        'replies' => fn ($replyQuery) => $replyQuery
                            ->orderBy('created_at')
                            ->with([
                                'author.roles',
                                'replyToUser.roles',
                                'likes' => fn ($likeQuery) => $likeQuery->where('user_id', $user->id),
                            ]),
                    ]),
            ])
            ->findOrFail($postId);
    }

    private function loadCommentForUser(User $user, int $commentId): FeedComment
    {
        $comment = FeedComment::query()
            ->with([
                'author.roles',
                'replyToUser.roles',
                'likes' => fn ($query) => $query->where('user_id', $user->id),
                'replies' => fn ($query) => $query
                    ->orderBy('created_at')
                    ->with([
                        'author.roles',
                        'replyToUser.roles',
                        'likes' => fn ($likeQuery) => $likeQuery->where('user_id', $user->id),
                    ]),
                'post',
            ])
            ->findOrFail($commentId);

        $this->ensureCanViewPost($user, $comment->post);

        return $comment;
    }

    private function transformPost(FeedPost $post, User $viewer): array
    {
        $postOwnerId = (int) $post->user_id;
        $comments = $post->relationLoaded('rootComments')
            ? $post->rootComments
                ->map(fn (FeedComment $comment) => $this->transformComment($comment, $viewer, $postOwnerId))
                ->values()
                ->all()
            : [];

        return [
            'id' => (string) $post->id,
            'content' => $post->content,
            'visibility' => $post->visibility,
            'visibility_label' => $this->visibilityLabel($post),
            'selected_recipient_count' => (int) ($post->recipients_count ?? 0),
            'audience_user_ids' => $post->relationLoaded('recipients')
                ? $post->recipients
                    ->pluck('id')
                    ->map(fn ($userId) => (string) $userId)
                    ->values()
                    ->all()
                : [],
            'likes_count' => (int) $post->like_count,
            'comments_count' => (int) $post->comment_count,
            'liked_by_me' => $post->relationLoaded('likes') ? $post->likes->isNotEmpty() : false,
            'can_delete' => $this->canDeletePost($viewer, $post),
            'created_at' => optional($post->created_at)?->toISOString(),
            'updated_at' => optional($post->updated_at)?->toISOString(),
            'last_activity_at' => optional($post->last_activity_at ?? $post->created_at)?->toISOString(),
            'author' => $this->transformAuthor($post->author),
            'comments' => $comments,
        ];
    }

    private function transformComment(FeedComment $comment, User $viewer, ?int $postOwnerId = null): array
    {
        $replies = $comment->relationLoaded('replies')
            ? $comment->replies
                ->map(fn (FeedComment $reply) => $this->transformComment($reply, $viewer, $postOwnerId))
                ->values()
                ->all()
            : [];

        return [
            'id' => (string) $comment->id,
            'post_id' => (string) $comment->post_id,
            'parent_id' => $comment->parent_id === null ? null : (string) $comment->parent_id,
            'reply_to_comment_id' => $comment->reply_to_comment_id === null
                ? null
                : (string) $comment->reply_to_comment_id,
            'content' => $comment->content,
            'likes_count' => (int) $comment->like_count,
            'reply_count' => (int) $comment->reply_count,
            'liked_by_me' => $comment->relationLoaded('likes') ? $comment->likes->isNotEmpty() : false,
            'can_delete' => $this->canDeleteComment($viewer, $comment, $postOwnerId),
            'created_at' => optional($comment->created_at)?->toISOString(),
            'updated_at' => optional($comment->updated_at)?->toISOString(),
            'author' => $this->transformAuthor($comment->author),
            'reply_to_user' => $comment->relationLoaded('replyToUser') && $comment->replyToUser !== null
                ? $this->transformAuthor($comment->replyToUser)
                : null,
            'replies' => $replies,
        ];
    }

    private function transformAuthor(?User $user): array
    {
        $name = trim((string) ($user?->name ?? 'Internal User'));
        $parts = collect(preg_split('/\s+/', $name) ?: [])
            ->filter(fn ($part) => trim((string) $part) !== '')
            ->values();

        $initials = 'IU';
        if ($parts->count() === 1) {
            $initials = strtoupper(substr($parts->first(), 0, 2));
        } elseif ($parts->count() >= 2) {
            $initials = strtoupper(substr((string) $parts->first(), 0, 1).substr((string) $parts->last(), 0, 1));
        }

        return [
            'id' => (string) ($user?->id ?? ''),
            'name' => $name,
            'initials' => $initials,
            'department' => $user?->department,
            'primary_role' => $user?->roles?->pluck('name')->first() ?? 'Internal',
        ];
    }

    private function visibilityLabel(FeedPost $post): string
    {
        return match ($post->visibility) {
            FeedPost::VISIBILITY_DEPARTMENT => trim((string) $post->target_department) !== ''
                ? 'Divisi '.$post->target_department
                : 'Satu divisi',
            FeedPost::VISIBILITY_SELECTED_USERS => (int) ($post->recipients_count ?? 0) > 0
                ? 'Orang tertentu ('.(int) $post->recipients_count.')'
                : 'Orang tertentu',
            FeedPost::VISIBILITY_PRIVATE => 'Hanya saya',
            default => 'Semua orang',
        };
    }

    private function canDeletePost(User $user, FeedPost $post): bool
    {
        return (int) $post->user_id === (int) $user->id || $user->hasRole('Admin');
    }

    private function canDeleteComment(User $user, FeedComment $comment, ?int $postOwnerId = null): bool
    {
        $resolvedPostOwnerId = $postOwnerId
            ?? ($comment->relationLoaded('post')
                ? (int) $comment->post->user_id
                : (int) FeedPost::query()->whereKey($comment->post_id)->value('user_id'));

        return (int) $comment->user_id === (int) $user->id
            || $resolvedPostOwnerId === (int) $user->id
            || $user->hasRole('Admin');
    }

    private function ensureCanViewPost(User $user, FeedPost $post): void
    {
        if ($post->visibility === FeedPost::VISIBILITY_PUBLIC) {
            return;
        }

        if ((int) $post->user_id === (int) $user->id) {
            return;
        }

        if (
            $post->visibility === FeedPost::VISIBILITY_DEPARTMENT
            && trim((string) ($user->department ?? '')) !== ''
            && trim((string) $post->target_department) === trim((string) $user->department)
        ) {
            return;
        }

        if (
            $post->visibility === FeedPost::VISIBILITY_SELECTED_USERS
            && $post->recipients()->where('users.id', $user->id)->exists()
        ) {
            return;
        }

        abort(404);
    }

    private function ensureCanDeletePost(User $user, FeedPost $post): void
    {
        if ($this->canDeletePost($user, $post)) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'error' => 'Anda tidak bisa menghapus postingan ini.',
        ], 403));
    }

    private function ensureCanDeleteComment(User $user, FeedComment $comment): void
    {
        if ($this->canDeleteComment($user, $comment)) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'error' => 'Anda tidak bisa menghapus komentar ini.',
        ], 403));
    }

    private function trimmedText(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new HttpResponseException(response()->json([
                'error' => 'Teks tidak boleh kosong.',
            ], 422));
        }

        return $trimmed;
    }

    private function toggleLike(Model $likeable, User $user): bool
    {
        $existingLike = $likeable->likes()
            ->where('user_id', $user->id)
            ->first();

        if ($existingLike !== null) {
            $existingLike->delete();
            $likeable->decrement('like_count');

            return false;
        }

        $likeable->likes()->create([
            'user_id' => $user->id,
        ]);
        $likeable->increment('like_count');

        return true;
    }

    private function dispatchPostNotifications(FeedPost $post, User $actor): void
    {
        $link = '/feed/posts/'.$post->id;
        $actorName = trim((string) $actor->name);

        foreach ($this->visibleAudienceUserIds($post) as $userId) {
            if ((int) $userId === (int) $actor->id) {
                continue;
            }

            $this->createNotification(
                (int) $userId,
                'Thread baru di feed',
                $actorName.' membagikan thread baru untuk Anda.',
                $link,
                'feed_thread',
            );
        }
    }

    private function dispatchCommentNotifications(
        FeedPost $post,
        User $actor,
        ?FeedComment $parent,
        array $mentionedUserIds = [],
    ): void
    {
        $link = '/feed/posts/'.$post->id;
        $actorName = trim((string) $actor->name);
        $visibleAudienceUserIds = collect($this->visibleAudienceUserIds($post))
            ->map(fn ($userId) => (int) $userId)
            ->values();
        $notificationsByUserId = [];

        foreach ($mentionedUserIds as $mentionedUserId) {
            $resolvedUserId = (int) $mentionedUserId;
            if (
                $resolvedUserId <= 0
                || $resolvedUserId === (int) $actor->id
                || ! $visibleAudienceUserIds->contains($resolvedUserId)
            ) {
                continue;
            }

            $notificationsByUserId[$resolvedUserId] = [
                'title' => 'Anda disebut di thread',
                'message' => $actorName.' menyebut Anda di komentar thread.',
                'type' => 'feed_mention',
            ];
        }

        if ($parent === null) {
            if ((int) $post->user_id !== (int) $actor->id) {
                $notificationsByUserId[(int) $post->user_id] ??= [
                    'title' => 'Komentar baru di feed',
                    'message' => $actorName.' mengomentari update Anda.',
                    'type' => 'feed_comment',
                ];
            }
        } else {
            if ((int) $parent->user_id !== (int) $actor->id) {
                $notificationsByUserId[(int) $parent->user_id] ??= [
                    'title' => 'Balasan komentar baru',
                    'message' => $actorName.' membalas komentar Anda.',
                    'type' => 'feed_comment',
                ];
            }

            if (
                (int) $post->user_id !== (int) $actor->id
                && (int) $post->user_id !== (int) $parent->user_id
            ) {
                $notificationsByUserId[(int) $post->user_id] ??= [
                    'title' => 'Aktivitas baru di feed',
                    'message' => $actorName.' membalas thread Anda.',
                    'type' => 'feed_comment',
                ];
            }
        }

        foreach ($notificationsByUserId as $userId => $payload) {
            if (! $visibleAudienceUserIds->contains((int) $userId)) {
                continue;
            }

            $this->createNotification(
                (int) $userId,
                $payload['title'],
                $payload['message'],
                $link,
                $payload['type'],
            );
        }
    }

    private function createNotification(
        int $userId,
        string $title,
        string $message,
        string $link,
        string $type = 'general',
    ): void
    {
        Notification::query()->create([
            'user_id' => $userId,
            'title' => $title,
            'message' => $message,
            'type' => 'general',
            'link' => $link,
            'is_read' => false,
        ]);
    }

    private function normalizeRecipientUserIds(array $rawUserIds, User $actor): array
    {
        $candidateUserIds = collect($rawUserIds)
            ->map(fn ($userId) => (int) $userId)
            ->filter(fn (int $userId) => $userId > 0 && $userId !== (int) $actor->id)
            ->unique()
            ->values();

        if ($candidateUserIds->isEmpty()) {
            return [];
        }

        return User::query()
            ->where('is_active', true)
            ->whereIn('id', $candidateUserIds->all())
            ->pluck('id')
            ->map(fn ($userId) => (int) $userId)
            ->values()
            ->all();
    }

    private function normalizeMentionedUserIds(
        FeedPost $post,
        string $content,
        User $actor,
        array $rawMentionedUserIds,
    ): array {
        $explicitMentionedUserIds = collect($rawMentionedUserIds)
            ->map(fn ($userId) => (int) $userId)
            ->filter(fn (int $userId) => $userId > 0 && $userId !== (int) $actor->id)
            ->values();

        $visibleAudienceUserIds = collect($this->visibleAudienceUserIds($post))
            ->map(fn ($userId) => (int) $userId)
            ->filter(fn (int $userId) => $userId !== (int) $actor->id)
            ->values();

        $parsedMentionedUserIds = [];
        $mentionCandidates = User::query()
            ->where('is_active', true)
            ->whereIn('id', $visibleAudienceUserIds->all())
            ->get(['id', 'name']);

        $mentionCandidates = $mentionCandidates
            ->sortByDesc(fn (User $candidate) => mb_strlen(trim((string) $candidate->name)))
            ->values();

        foreach ($mentionCandidates as $candidate) {
            $candidateName = trim((string) $candidate->name);
            if ($candidateName === '') {
                continue;
            }

            if (mb_stripos($content, '@'.$candidateName) !== false) {
                $parsedMentionedUserIds[] = (int) $candidate->id;
            }
        }

        return $explicitMentionedUserIds
            ->merge($parsedMentionedUserIds)
            ->filter(fn (int $userId) => $visibleAudienceUserIds->contains($userId))
            ->unique()
            ->values()
            ->all();
    }

    private function visibleAudienceUserIds(FeedPost $post): array
    {
        if ($post->visibility === FeedPost::VISIBILITY_PRIVATE) {
            return [(int) $post->user_id];
        }

        if ($post->visibility === FeedPost::VISIBILITY_DEPARTMENT) {
            $department = trim((string) $post->target_department);
            if ($department === '') {
                return [(int) $post->user_id];
            }

            return User::query()
                ->where('is_active', true)
                ->where('department', $department)
                ->pluck('id')
                ->map(fn ($userId) => (int) $userId)
                ->unique()
                ->values()
                ->all();
        }

        if ($post->visibility === FeedPost::VISIBILITY_SELECTED_USERS) {
            return $post->recipients()
                ->where('users.is_active', true)
                ->pluck('users.id')
                ->map(fn ($userId) => (int) $userId)
                ->push((int) $post->user_id)
                ->unique()
                ->values()
                ->all();
        }

        return User::query()
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($userId) => (int) $userId)
            ->unique()
            ->values()
            ->all();
    }
}

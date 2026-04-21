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
                    ]),
                ],
            ]);

            /** @var User $user */
            $user = $request->user()->loadMissing('roles');
            $content = $this->trimmedText($validated['content']);
            $visibility = $validated['visibility'];
            $department = trim((string) ($user->department ?? ''));

            if ($visibility === FeedPost::VISIBILITY_DEPARTMENT && $department === '') {
                throw new HttpResponseException(response()->json([
                    'error' => 'Posting untuk divisi membutuhkan data department pada akun Anda.',
                ], 422));
            }

            $post = DB::transaction(function () use ($user, $content, $visibility, $department) {
                return FeedPost::query()->create([
                    'user_id' => $user->id,
                    'visibility' => $visibility,
                    'target_department' => $visibility === FeedPost::VISIBILITY_DEPARTMENT ? $department : null,
                    'content' => $content,
                    'last_activity_at' => now(),
                ]);
            });

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
            ]);

            /** @var User $user */
            $user = $request->user()->loadMissing('roles');
            $this->ensureCanViewPost($user, $post);

            $content = $this->trimmedText($validated['content']);
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

            $comment = DB::transaction(function () use ($post, $user, $content, $replyTarget, $rootComment) {
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

                $this->dispatchCommentNotifications($post, $user, $replyTarget);

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
            ->with([
                'author.roles',
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

    private function dispatchCommentNotifications(FeedPost $post, User $actor, ?FeedComment $parent): void
    {
        $link = '/feed/posts/'.$post->id;
        $actorName = trim((string) $actor->name);

        if ($parent === null) {
            if ((int) $post->user_id !== (int) $actor->id) {
                $this->createNotification(
                    (int) $post->user_id,
                    'Komentar baru di feed',
                    $actorName.' mengomentari update Anda.',
                    $link,
                );
            }

            return;
        }

        if ((int) $parent->user_id !== (int) $actor->id) {
            $this->createNotification(
                (int) $parent->user_id,
                'Balasan komentar baru',
                $actorName.' membalas komentar Anda.',
                $link,
            );
        }

        if (
            (int) $post->user_id !== (int) $actor->id
            && (int) $post->user_id !== (int) $parent->user_id
        ) {
            $this->createNotification(
                (int) $post->user_id,
                'Aktivitas baru di feed',
                $actorName.' membalas thread Anda.',
                $link,
            );
        }
    }

    private function createNotification(int $userId, string $title, string $message, string $link): void
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
}

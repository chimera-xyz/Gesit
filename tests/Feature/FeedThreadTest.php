<?php

namespace Tests\Feature;

use App\Models\FeedPost;
use App\Models\Notification;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedThreadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_feed_list_respects_public_department_and_private_visibility(): void
    {
        $author = $this->makeUserWithRole('Employee', [
            'name' => 'Raihan Ops',
            'email' => 'raihan.feed@example.com',
            'department' => 'Operations',
        ]);
        $sameDepartmentViewer = $this->makeUserWithRole('Employee', [
            'name' => 'Nadia Ops',
            'email' => 'nadia.feed@example.com',
            'department' => 'Operations',
        ]);
        $otherDepartmentViewer = $this->makeUserWithRole('IT Staff', [
            'name' => 'Budi IT',
            'email' => 'budi.feed@example.com',
            'department' => 'IT',
        ]);

        $publicPost = FeedPost::query()->create([
            'user_id' => $author->id,
            'visibility' => FeedPost::VISIBILITY_PUBLIC,
            'content' => 'Update umum untuk semua tim.',
            'last_activity_at' => now()->subMinutes(3),
        ]);
        $departmentPost = FeedPost::query()->create([
            'user_id' => $author->id,
            'visibility' => FeedPost::VISIBILITY_DEPARTMENT,
            'target_department' => 'Operations',
            'content' => 'Hanya untuk tim Operations.',
            'last_activity_at' => now()->subMinutes(2),
        ]);
        $privatePost = FeedPost::query()->create([
            'user_id' => $author->id,
            'visibility' => FeedPost::VISIBILITY_PRIVATE,
            'content' => 'Catatan pribadi untuk saya sendiri.',
            'last_activity_at' => now()->subMinute(),
        ]);

        $sameDepartmentResponse = $this->actingAs($sameDepartmentViewer)
            ->getJson('/api/feed')
            ->assertOk();

        $this->assertSame(
            [(string) $departmentPost->id, (string) $publicPost->id],
            collect($sameDepartmentResponse->json('posts'))->pluck('id')->all(),
        );

        $otherDepartmentResponse = $this->actingAs($otherDepartmentViewer)
            ->getJson('/api/feed')
            ->assertOk();

        $this->assertSame(
            [(string) $publicPost->id],
            collect($otherDepartmentResponse->json('posts'))->pluck('id')->all(),
        );

        $authorResponse = $this->actingAs($author)
            ->getJson('/api/feed')
            ->assertOk();

        $this->assertSame(
            [(string) $privatePost->id, (string) $departmentPost->id, (string) $publicPost->id],
            collect($authorResponse->json('posts'))->pluck('id')->all(),
        );
    }

    public function test_comment_and_reply_to_reply_stay_in_one_visible_thread_level(): void
    {
        $author = $this->makeUserWithRole('Employee', [
            'name' => 'Raihan Ops',
            'email' => 'raihan.comment@example.com',
            'department' => 'Operations',
        ]);
        $commenter = $this->makeUserWithRole('IT Staff', [
            'name' => 'Nadia IT',
            'email' => 'nadia.comment@example.com',
            'department' => 'IT',
        ]);
        $replier = $this->makeUserWithRole('Accounting', [
            'name' => 'Dina Finance',
            'email' => 'dina.comment@example.com',
            'department' => 'Finance',
        ]);

        $post = FeedPost::query()->create([
            'user_id' => $author->id,
            'visibility' => FeedPost::VISIBILITY_PUBLIC,
            'content' => 'Besok ada MCU, mohon semua siap sebelum jam 9.',
            'last_activity_at' => now(),
        ]);

        $commentResponse = $this->actingAs($commenter)
            ->postJson("/api/feed/posts/{$post->id}/comments", [
                'content' => 'Siap, nanti saya bantu remind tim juga.',
            ])
            ->assertCreated()
            ->assertJsonPath('post.comments_count', 1);

        $commentId = $commentResponse->json('comment.id');

        $this->assertDatabaseHas('feed_posts', [
            'id' => $post->id,
            'comment_count' => 1,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $author->id,
            'title' => 'Komentar baru di feed',
            'link' => "/feed/posts/{$post->id}",
            'type' => 'general',
        ]);

        $firstReplyResponse = $this->actingAs($replier)
            ->postJson("/api/feed/posts/{$post->id}/comments", [
                'content' => 'Sudah saya teruskan ke tim Finance juga.',
                'parent_id' => $commentId,
            ])
            ->assertCreated()
            ->assertJsonPath('post.comments_count', 2);

        $firstReplyId = $firstReplyResponse->json('comment.id');

        $this->assertDatabaseHas('feed_comments', [
            'id' => $commentId,
            'reply_count' => 1,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $commenter->id,
            'title' => 'Balasan komentar baru',
            'link' => "/feed/posts/{$post->id}",
            'type' => 'general',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $author->id,
            'title' => 'Aktivitas baru di feed',
            'link' => "/feed/posts/{$post->id}",
            'type' => 'general',
        ]);

        $secondReplyResponse = $this->actingAs($commenter)
            ->postJson("/api/feed/posts/{$post->id}/comments", [
                'content' => 'Siap, saya follow up lagi ke tim IT.',
                'parent_id' => $firstReplyId,
            ])
            ->assertCreated()
            ->assertJsonPath('post.comments_count', 3)
            ->assertJsonPath('comment.parent_id', $commentId)
            ->assertJsonPath('comment.reply_to_comment_id', $firstReplyId)
            ->assertJsonPath('comment.reply_to_user.name', 'Dina Finance');

        $secondReplyId = $secondReplyResponse->json('comment.id');

        $this->assertDatabaseHas('feed_comments', [
            'id' => $commentId,
            'reply_count' => 2,
        ]);
        $this->assertDatabaseHas('feed_comments', [
            'id' => $secondReplyId,
            'parent_id' => $commentId,
            'reply_to_comment_id' => $firstReplyId,
            'reply_to_user_id' => $replier->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $replier->id,
            'title' => 'Balasan komentar baru',
            'link' => "/feed/posts/{$post->id}",
            'type' => 'general',
        ]);

        $threadResponse = $this->actingAs($author)
            ->getJson("/api/feed/posts/{$post->id}")
            ->assertOk();

        $this->assertCount(1, $threadResponse->json('post.comments'));
        $this->assertCount(2, $threadResponse->json('post.comments.0.replies'));
        $this->assertSame(
            [$firstReplyId, $secondReplyId],
            collect($threadResponse->json('post.comments.0.replies'))->pluck('id')->all(),
        );
    }

    public function test_like_toggle_and_reply_deletion_keep_feed_counts_consistent(): void
    {
        $author = $this->makeUserWithRole('Employee', [
            'name' => 'Raihan Ops',
            'email' => 'raihan.like@example.com',
            'department' => 'Operations',
        ]);
        $participant = $this->makeUserWithRole('IT Staff', [
            'name' => 'Budi IT',
            'email' => 'budi.like@example.com',
            'department' => 'IT',
        ]);

        $post = FeedPost::query()->create([
            'user_id' => $author->id,
            'visibility' => FeedPost::VISIBILITY_PUBLIC,
            'content' => 'Cek kesiapan inventaris sebelum audit mingguan.',
            'last_activity_at' => now(),
        ]);

        $this->actingAs($participant)
            ->postJson("/api/feed/posts/{$post->id}/likes/toggle")
            ->assertOk()
            ->assertJsonPath('liked', true)
            ->assertJsonPath('post.likes_count', 1);

        $this->actingAs($participant)
            ->postJson("/api/feed/posts/{$post->id}/likes/toggle")
            ->assertOk()
            ->assertJsonPath('liked', false)
            ->assertJsonPath('post.likes_count', 0);

        $commentResponse = $this->actingAs($participant)
            ->postJson("/api/feed/posts/{$post->id}/comments", [
                'content' => 'Siap, saya cek sore ini.',
            ])
            ->assertCreated();

        $commentId = $commentResponse->json('comment.id');

        $replyResponse = $this->actingAs($author)
            ->postJson("/api/feed/posts/{$post->id}/comments", [
                'content' => 'Oke, kabari hasilnya di thread ini ya.',
                'parent_id' => $commentId,
            ])
            ->assertCreated();

        $replyId = $replyResponse->json('comment.id');

        $nestedReplyResponse = $this->actingAs($participant)
            ->postJson("/api/feed/posts/{$post->id}/comments", [
                'content' => 'Siap, saya update lagi setelah pengecekan.',
                'parent_id' => $replyId,
            ])
            ->assertCreated()
            ->assertJsonPath('comment.parent_id', $commentId)
            ->assertJsonPath('comment.reply_to_comment_id', $replyId);

        $nestedReplyId = $nestedReplyResponse->json('comment.id');

        $this->actingAs($author)
            ->postJson("/api/feed/comments/{$commentId}/likes/toggle")
            ->assertOk()
            ->assertJsonPath('liked', true)
            ->assertJsonPath('comment.likes_count', 1);

        $this->actingAs($author)
            ->deleteJson("/api/feed/comments/{$replyId}")
            ->assertOk()
            ->assertJsonPath('post.comments_count', 2);

        $this->assertDatabaseHas('feed_comments', [
            'id' => $commentId,
        ]);
        $this->assertDatabaseHas('feed_comments', [
            'id' => $nestedReplyId,
        ]);
        $this->assertDatabaseMissing('feed_comments', [
            'id' => $replyId,
        ]);
        $this->assertDatabaseHas('feed_comments', [
            'id' => $nestedReplyId,
            'parent_id' => $commentId,
            'reply_to_comment_id' => null,
            'reply_to_user_id' => $author->id,
        ]);
        $this->assertDatabaseHas('feed_posts', [
            'id' => $post->id,
            'comment_count' => 2,
            'like_count' => 0,
        ]);
        $this->assertDatabaseHas('feed_comments', [
            'id' => $commentId,
            'reply_count' => 1,
        ]);
    }

    public function test_selected_user_visibility_only_surfaces_to_targeted_users_and_sends_new_thread_notification(): void
    {
        $author = $this->makeUserWithRole('Employee', [
            'name' => 'Raihan Ops',
            'email' => 'raihan.selected@example.com',
            'department' => 'Operations',
        ]);
        $targetUser = $this->makeUserWithRole('Accounting', [
            'name' => 'Dina Finance',
            'email' => 'dina.selected@example.com',
            'department' => 'Finance',
        ]);
        $otherUser = $this->makeUserWithRole('IT Staff', [
            'name' => 'Budi IT',
            'email' => 'budi.selected@example.com',
            'department' => 'IT',
        ]);

        $response = $this->actingAs($author)
            ->postJson('/api/feed/posts', [
                'content' => 'Thread ini hanya untuk Dina.',
                'visibility' => FeedPost::VISIBILITY_SELECTED_USERS,
                'recipient_user_ids' => [$targetUser->id],
            ])
            ->assertCreated()
            ->assertJsonPath('post.visibility', FeedPost::VISIBILITY_SELECTED_USERS)
            ->assertJsonPath('post.selected_recipient_count', 1);

        $postId = (int) $response->json('post.id');

        $this->assertDatabaseHas('feed_post_recipients', [
            'feed_post_id' => $postId,
            'user_id' => $targetUser->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $targetUser->id,
            'title' => 'Thread baru di feed',
            'link' => "/feed/posts/{$postId}",
            'type' => 'general',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $otherUser->id,
            'link' => "/feed/posts/{$postId}",
        ]);

        $this->actingAs($targetUser)
            ->getJson('/api/feed')
            ->assertOk()
            ->assertJsonPath('posts.0.id', (string) $postId);

        $this->actingAs($otherUser)
            ->getJson('/api/feed')
            ->assertOk()
            ->assertJsonCount(0, 'posts');
    }

    public function test_comment_mention_notifies_post_owner_and_mentioned_user_without_duplicates(): void
    {
        $author = $this->makeUserWithRole('Employee', [
            'name' => 'Raihan Ops',
            'email' => 'raihan.mention@example.com',
            'department' => 'Operations',
        ]);
        $commenter = $this->makeUserWithRole('IT Staff', [
            'name' => 'Budi IT',
            'email' => 'budi.mention@example.com',
            'department' => 'IT',
        ]);
        $mentionedUser = $this->makeUserWithRole('Accounting', [
            'name' => 'Dina Finance',
            'email' => 'dina.mention@example.com',
            'department' => 'Finance',
        ]);

        $post = FeedPost::query()->create([
            'user_id' => $author->id,
            'visibility' => FeedPost::VISIBILITY_PUBLIC,
            'content' => 'Mohon follow up vendor minggu ini.',
            'last_activity_at' => now(),
        ]);

        $this->actingAs($commenter)
            ->postJson("/api/feed/posts/{$post->id}/comments", [
                'content' => 'Siap, saya mention @Dina Finance buat cek invoice.',
                'mentioned_user_ids' => [$mentionedUser->id],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $author->id,
            'title' => 'Komentar baru di feed',
            'link' => "/feed/posts/{$post->id}",
            'type' => 'general',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $mentionedUser->id,
            'title' => 'Anda disebut di thread',
            'link' => "/feed/posts/{$post->id}",
            'type' => 'general',
        ]);
        $this->assertSame(
            2,
            Notification::query()
                ->where('link', "/feed/posts/{$post->id}")
                ->count(),
        );
    }

    private function makeUserWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }
}

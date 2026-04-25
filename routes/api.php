<?php

use App\Http\Controllers\API\AdminKnowledgeController;
use App\Http\Controllers\API\AdminRoleController;
use App\Http\Controllers\API\AdminUserController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\ChatController;
use App\Http\Controllers\API\FormController;
use App\Http\Controllers\API\FormSubmissionController;
use App\Http\Controllers\API\FeedController;
use App\Http\Controllers\API\HelpdeskTicketController;
use App\Http\Controllers\API\ItActivityController;
use App\Http\Controllers\API\KnowledgeHubController;
use App\Http\Controllers\API\MobileAppReleaseController;
use App\Http\Controllers\API\NotificationController;
use App\Http\Controllers\API\PDFController;
use App\Http\Controllers\API\SignatureController;
use App\Http\Controllers\API\Submissions\CreateController;
use App\Http\Controllers\API\Submissions\IndexController;
use App\Http\Controllers\API\Submissions\ViewController;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::get('/mobile-app/releases/latest', [MobileAppReleaseController::class, 'latest']);
    Route::get('/mobile-app/releases/{id}/download', [MobileAppReleaseController::class, 'download'])
        ->name('api.mobile-app.releases.download')
        ->middleware('signed:relative');

    Route::post('/auth/biometric-login', [AuthController::class, 'biometricLogin']);
    Route::post('/auth/logout', [AuthController::class, 'logout'])
        ->middleware('auth');

    Route::middleware(['auth', 'active_user'])->group(function () {
        Route::post('/auth/biometric-enroll', [AuthController::class, 'enrollBiometric']);

        // User Routes
        Route::get('/user', [UserController::class, 'currentUser']);
        Route::get('/user/profile', [UserController::class, 'profile']);
        Route::put('/user/profile', [UserController::class, 'update']);
        Route::put('/user/change-password', [UserController::class, 'changePassword']);
        Route::get('/users', [AdminUserController::class, 'index'])
            ->middleware('role:Admin');
        Route::post('/users', [AdminUserController::class, 'store'])
            ->middleware('role:Admin');
        Route::put('/users/{id}', [AdminUserController::class, 'update'])
            ->middleware('role:Admin');
        Route::delete('/users/{id}', [AdminUserController::class, 'destroy'])
            ->middleware('role:Admin');
        Route::get('/roles', [AdminRoleController::class, 'index'])
            ->middleware('role:Admin');
        Route::post('/roles', [AdminRoleController::class, 'store'])
            ->middleware('role:Admin');
        Route::put('/roles/{id}', [AdminRoleController::class, 'update'])
            ->middleware('role:Admin');
        Route::delete('/roles/{id}', [AdminRoleController::class, 'destroy'])
            ->middleware('role:Admin');
        Route::get('/mobile-app/releases', [MobileAppReleaseController::class, 'index'])
            ->middleware('role:Admin');
        Route::post('/mobile-app/releases', [MobileAppReleaseController::class, 'store'])
            ->middleware('role:Admin');
        Route::put('/mobile-app/releases/{id}', [MobileAppReleaseController::class, 'update'])
            ->middleware('role:Admin');
        Route::post('/mobile-app/releases/{id}/publish', [MobileAppReleaseController::class, 'publish'])
            ->middleware('role:Admin');
        Route::post('/mobile-app/releases/{id}/unpublish', [MobileAppReleaseController::class, 'unpublish'])
            ->middleware('role:Admin');
        Route::delete('/mobile-app/releases/{id}', [MobileAppReleaseController::class, 'destroy'])
            ->middleware('role:Admin');
        Route::get('/knowledge-admin', [AdminKnowledgeController::class, 'index'])
            ->middleware('permission:manage knowledge hub');
        Route::put('/knowledge-admin/general', [AdminKnowledgeController::class, 'updateGeneral'])
            ->middleware('permission:manage knowledge hub');
        Route::post('/knowledge-admin/spaces', [AdminKnowledgeController::class, 'storeSpace'])
            ->middleware('permission:manage knowledge hub');
        Route::put('/knowledge-admin/spaces/{id}', [AdminKnowledgeController::class, 'updateSpace'])
            ->middleware('permission:manage knowledge hub');
        Route::delete('/knowledge-admin/spaces/{id}', [AdminKnowledgeController::class, 'destroySpace'])
            ->middleware('permission:manage knowledge hub');
        Route::post('/knowledge-admin/sections', [AdminKnowledgeController::class, 'storeSection'])
            ->middleware('permission:manage knowledge hub');
        Route::put('/knowledge-admin/sections/{id}', [AdminKnowledgeController::class, 'updateSection'])
            ->middleware('permission:manage knowledge hub');
        Route::delete('/knowledge-admin/sections/{id}', [AdminKnowledgeController::class, 'destroySection'])
            ->middleware('permission:manage knowledge hub');
        Route::post('/knowledge-admin/entries', [AdminKnowledgeController::class, 'storeEntry'])
            ->middleware('permission:manage knowledge hub');
        Route::post('/knowledge-admin/entries/{id}', [AdminKnowledgeController::class, 'updateEntry'])
            ->middleware('permission:manage knowledge hub');
        Route::delete('/knowledge-admin/entries/{id}', [AdminKnowledgeController::class, 'destroyEntry'])
            ->middleware('permission:manage knowledge hub');

        Route::get('/knowledge-hub', [KnowledgeHubController::class, 'index'])
            ->middleware('permission:view knowledge hub');
        Route::get('/knowledge-hub/conversations', [KnowledgeHubController::class, 'conversations'])
            ->middleware('permission:view knowledge hub');
        Route::get('/knowledge-hub/conversations/{id}', [KnowledgeHubController::class, 'showConversation'])
            ->middleware('permission:view knowledge hub');
        Route::patch('/knowledge-hub/conversations/{id}', [KnowledgeHubController::class, 'updateConversation'])
            ->middleware('permission:view knowledge hub');
        Route::delete('/knowledge-hub/conversations/{id}', [KnowledgeHubController::class, 'destroyConversation'])
            ->middleware('permission:view knowledge hub');
        Route::post('/knowledge-hub/ask', [KnowledgeHubController::class, 'ask'])
            ->middleware('permission:view knowledge hub');
        Route::post('/knowledge-hub/conversations/{id}/actions', [KnowledgeHubController::class, 'performConversationAction'])
            ->middleware('permission:view knowledge hub');
        Route::post('/knowledge-hub/entries/{id}/bookmark', [KnowledgeHubController::class, 'toggleBookmark'])
            ->middleware('permission:view knowledge hub');
        Route::post('/knowledge-hub/spaces/{spaceId}/folders', [KnowledgeHubController::class, 'storeFolder'])
            ->middleware('permission:view knowledge hub');
        Route::post('/knowledge-hub/spaces/{spaceId}/entries', [KnowledgeHubController::class, 'storeEntry'])
            ->middleware('permission:view knowledge hub');

        // PDF Generation Routes
        Route::post('/pdf/generate/{id}', [PDFController::class, 'generate'])
            ->middleware('permission:view submissions');

        Route::get('/pdf/preview/{id}', [PDFController::class, 'preview'])
            ->middleware('permission:view submissions');

        Route::get('/pdf/stream/{id}', [PDFController::class, 'stream'])
            ->middleware('permission:view submissions');

        Route::get('/pdf/download/{id}', [PDFController::class, 'download'])
            ->middleware('permission:view submissions');

        // Digital Signature Routes
        Route::post('/signature/draw', [SignatureController::class, 'draw'])
            ->middleware('permission:create signatures');

        Route::post('/signature/upload', [SignatureController::class, 'upload'])
            ->middleware('permission:create signatures');

        Route::get('/signature/verify/{id}', [SignatureController::class, 'verify']);
        Route::get('/signature/user-signatures', [SignatureController::class, 'userSignatures']);
        Route::delete('/signature/{id}', [SignatureController::class, 'destroy']);

        // Form Management Routes
        Route::get('/forms', [FormController::class, 'index'])
            ->middleware('permission:view forms');

        Route::get('/forms/{id}', [FormController::class, 'show'])
            ->middleware('permission:view forms');

        Route::post('/forms', [FormController::class, 'store'])
            ->middleware('permission:create forms');

        Route::put('/forms/{id}', [FormController::class, 'update'])
            ->middleware('permission:edit forms');

        Route::delete('/forms/{id}', [FormController::class, 'destroy'])
            ->middleware('permission:delete forms');

        // Form Submission Routes
        Route::post('/form-submissions', [FormSubmissionController::class, 'store'])
            ->middleware('permission:submit forms');

        Route::get('/form-submissions', [FormSubmissionController::class, 'index'])
            ->middleware('permission:view submissions');

        Route::get('/form-submissions/{id}', [FormSubmissionController::class, 'show'])
            ->middleware('permission:view submissions');

        Route::put('/form-submissions/{id}/approve', [FormSubmissionController::class, 'approve'])
            ->middleware('permission:approve forms');

        Route::post('/form-submissions/{id}/reject', [FormSubmissionController::class, 'reject'])
            ->middleware('permission:reject forms');

        // Feed Routes
        Route::get('/feed', [FeedController::class, 'index']);
        Route::get('/feed/audience-members', [FeedController::class, 'audienceMembers']);
        Route::post('/feed/posts', [FeedController::class, 'store']);
        Route::get('/feed/posts/{post}', [FeedController::class, 'show']);
        Route::delete('/feed/posts/{post}', [FeedController::class, 'destroy']);
        Route::post('/feed/posts/{post}/likes/toggle', [FeedController::class, 'togglePostLike']);
        Route::post('/feed/posts/{post}/comments', [FeedController::class, 'storeComment']);
        Route::delete('/feed/comments/{comment}', [FeedController::class, 'destroyComment']);
        Route::post('/feed/comments/{comment}/likes/toggle', [FeedController::class, 'toggleCommentLike']);

        // Notification Routes
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/notifications/unread-feed', [NotificationController::class, 'unreadFeed']);
        Route::get('/notifications/stream', [NotificationController::class, 'stream']);
        Route::post('/notifications/devices', [NotificationController::class, 'registerDeviceToken']);
        Route::delete('/notifications/devices', [NotificationController::class, 'unregisterDeviceToken']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
        Route::delete('/notifications', [NotificationController::class, 'destroyAll']);
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);

        // Chat Workspace Routes
        Route::get('/chat/workspace', [ChatController::class, 'workspace']);
        Route::get('/chat/stream', [ChatController::class, 'stream']);
        Route::get('/chat/sync', [ChatController::class, 'sync']);
        Route::post('/chat/direct-conversations', [ChatController::class, 'ensureDirectConversation']);
        Route::post('/chat/conversations/{conversation}/messages', [ChatController::class, 'sendMessage']);
        Route::post('/chat/conversations/{conversation}/attachments', [ChatController::class, 'sendAttachment']);
        Route::post('/chat/conversations/{conversation}/read', [ChatController::class, 'markRead']);
        Route::patch('/chat/conversations/{conversation}/preferences', [ChatController::class, 'updatePreferences']);
        Route::post('/chat/conversations/{conversation}/calls', [ChatController::class, 'startCall']);
        Route::post('/chat/calls/{call}/accept', [ChatController::class, 'acceptCall']);
        Route::post('/chat/calls/{call}/decline', [ChatController::class, 'declineCall']);
        Route::post('/chat/calls/{call}/end', [ChatController::class, 'endCall']);
        Route::post('/chat/calls/{call}/signal', [ChatController::class, 'signalCall']);

        // Helpdesk Routes
        Route::get('/helpdesk/tickets', [HelpdeskTicketController::class, 'index'])
            ->middleware('permission:view helpdesk tickets');
        Route::post('/helpdesk/tickets', [HelpdeskTicketController::class, 'store'])
            ->middleware('permission:create helpdesk tickets');
        Route::get('/helpdesk/tickets/{id}', [HelpdeskTicketController::class, 'show'])
            ->middleware('permission:view helpdesk tickets');
        Route::put('/helpdesk/tickets/{id}', [HelpdeskTicketController::class, 'update'])
            ->middleware('permission:view helpdesk tickets');
        Route::post('/helpdesk/tickets/{id}/updates', [HelpdeskTicketController::class, 'addUpdate'])
            ->middleware('permission:view helpdesk tickets');

        // IT Activity Routes
        Route::get('/it-activities', [ItActivityController::class, 'index'])
            ->middleware('permission:view it activities');
        Route::get('/it-activities/export', [ItActivityController::class, 'export'])
            ->middleware('permission:export it activities');

        // Alternate submissions routes used by the frontend
        Route::get('/submissions', [IndexController::class, 'index'])
            ->middleware('permission:view submissions');

        Route::post('/submissions/create', [CreateController::class, 'store'])
            ->middleware('permission:submit forms');

        Route::get('/submissions/create', [CreateController::class, 'create'])
            ->middleware('permission:submit forms');

        Route::get('/submissions/{id}', [ViewController::class, 'view'])
            ->middleware('permission:view submissions');

        // Workflow Management Routes
        Route::post('/workflows', [WorkflowController::class, 'store'])
            ->middleware('permission:manage workflows');

        Route::get('/workflows', [WorkflowController::class, 'index'])
            ->middleware('permission:view submissions');

        Route::put('/workflows/{id}', [WorkflowController::class, 'update'])
            ->middleware('permission:manage workflows');

        Route::delete('/workflows/{id}', [WorkflowController::class, 'destroy'])
            ->middleware('permission:manage workflows');
    });
});

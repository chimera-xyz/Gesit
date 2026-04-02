<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\FormController;
use App\Http\Controllers\API\FormSubmissionController;
use App\Http\Controllers\API\Submissions\IndexController;
use App\Http\Controllers\API\Submissions\CreateController;
use App\Http\Controllers\API\Submissions\ViewController;
use App\Http\Controllers\API\WorkflowController;
use App\Http\Controllers\API\PDFController;
use App\Http\Controllers\API\SignatureController;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\NotificationController;

Route::middleware('web')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::post('/auth/login', [AuthController::class, 'login']);
        Route::post('/auth/register', [AuthController::class, 'register']);
    });

    Route::post('/auth/logout', [AuthController::class, 'logout'])
        ->middleware('auth');

    Route::middleware('auth')->group(function () {
        // User Routes
        Route::get('/user', [UserController::class, 'currentUser']);
        Route::get('/user/profile', [UserController::class, 'profile']);
        Route::put('/user/profile', [UserController::class, 'update']);
        Route::put('/user/change-password', [UserController::class, 'changePassword']);

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

        // Notification Routes
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);

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

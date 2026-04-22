<?php

use App\Http\Controllers\PortalController;
use Illuminate\Support\Facades\Route;

Route::view('/login', 'app')->name('login');
Route::view('/register', 'app')->name('register');

Route::middleware(['web', 'auth', 'active_user'])->group(function () {
    Route::get('/portal/apps/{app}/launch', [PortalController::class, 'launch'])
        ->name('portal.launch');
    Route::get('/portal/authorize', [PortalController::class, 'authorize'])
        ->name('portal.authorize');
});

Route::post('/portal/token', [PortalController::class, 'token'])
    ->middleware('web')
    ->name('portal.token');

Route::get('/portal/logout', [PortalController::class, 'logout'])
    ->middleware('web')
    ->name('portal.logout');

Route::view('/{any?}', 'app')
    ->where('any', '^(?!api|storage|up).*$');

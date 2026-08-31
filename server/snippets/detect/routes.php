<?php
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GitLabWebhookController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ProfileController;
use App\Http\Middleware\VerifyGitLabWebhookRequest;
use Illuminate\Support\Facades\Route;

basicFunc('whatever');

Route::get('/', [HomeController::class, 'show'])->name('home.show');

Route::group(function () {
    Route::middleware('signed')->group(function () {
        Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    });
});
Route::middleware([
    'auth',
    'verified',
    'within-current-organization',
])->group(function () {

    Route::get('dashboard', [DashboardController::class, 'show'])->name('dashboard');
});


Route::post('gitlab/webhook', [GitLabWebhookController::class, 'store'])
    ->withoutMiddleware('web')
    ->middleware(VerifyGitLabWebhookRequest::class)
    ->name('gitlab.webhook.store');

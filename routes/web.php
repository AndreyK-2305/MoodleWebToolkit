<?php

use App\Http\Controllers\AcademicProposalController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ArtifactController;
use App\Http\Controllers\ConfirmActionPasswordController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExecutionActionController;
use App\Http\Controllers\ExecutionController;
use App\Http\Controllers\ExecutionEventController;
use App\Http\Controllers\ExecutionReviewController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectWizardController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn (Request $request) => redirect()->route($request->user() ? 'dashboard' : 'login'))->name('home');

Route::middleware(['auth', 'active', 'verified', 'password.changed'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('projects', [ProjectController::class, 'store'])->middleware('action.confirmed')->name('projects.store');
    Route::get('projects/{project:uuid}', [ProjectController::class, 'show'])->name('projects.show');
    Route::post('projects/{project:uuid}/executions', [ExecutionController::class, 'store'])->middleware('action.confirmed')->name('projects.executions.store');
    Route::get('projects/{project:uuid}/executions/{execution:uuid}', [ExecutionController::class, 'show'])->name('projects.executions.show');
    Route::get('projects/{project:uuid}/executions/{execution:uuid}/events', [ExecutionEventController::class, 'index'])->name('projects.executions.events');
    Route::post('projects/{project:uuid}/executions/{execution:uuid}/cancel', [ExecutionActionController::class, 'cancel'])->middleware('action.confirmed')->name('projects.executions.cancel');
    Route::post('projects/{project:uuid}/executions/{execution:uuid}/resume', [ExecutionActionController::class, 'resume'])->middleware('action.confirmed')->name('projects.executions.resume');
    Route::post('projects/{project:uuid}/executions/{execution:uuid}/proposals', [AcademicProposalController::class, 'store'])->middleware('action.confirmed')->name('projects.executions.proposals.store');
    Route::post('projects/{project:uuid}/executions/{execution:uuid}/validate', [ExecutionReviewController::class, 'validateExecution'])->middleware('action.confirmed')->name('projects.executions.validate');
    Route::post('projects/{project:uuid}/executions/{execution:uuid}/finalize', [ExecutionReviewController::class, 'finalize'])->middleware('action.confirmed')->name('projects.executions.finalize');
    Route::get('projects/{project:uuid}/executions/{execution:uuid}/artifacts/{artifact}/download', [ArtifactController::class, 'download'])->name('projects.executions.artifacts.download');
    Route::post('projects/{project:uuid}/executions/{execution:uuid}/conflicts/{conflict}/resolve', [ExecutionActionController::class, 'resolve'])->middleware('action.confirmed')->name('projects.executions.conflicts.resolve');
    Route::patch('projects/{project:uuid}/wizard/basics', [ProjectWizardController::class, 'basics'])->middleware('action.confirmed')->name('projects.wizard.basics');
    Route::put('projects/{project:uuid}/wizard/instances', [ProjectWizardController::class, 'instances'])->middleware('action.confirmed')->name('projects.wizard.instances');
    Route::put('projects/{project:uuid}/wizard/options', [ProjectWizardController::class, 'options'])->middleware('action.confirmed')->name('projects.wizard.options');
    Route::post('projects/{project:uuid}/wizard/preflight', [ProjectWizardController::class, 'preflight'])->middleware('action.confirmed')->name('projects.wizard.preflight');
    Route::post('projects/{project:uuid}/wizard/confirm', [ProjectWizardController::class, 'confirm'])->middleware('action.confirmed')->name('projects.wizard.confirm');
    Route::post('auth/confirm-action-password', ConfirmActionPasswordController::class)
        ->middleware('throttle:action-confirmation')
        ->name('action-password.confirm');
    Route::inertia('manuals', 'manuals')->name('manuals.index');
    Route::inertia('about', 'about')->name('about');

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users', [UserController::class, 'store'])->middleware('action.confirmed')->name('users.store');
        Route::patch('users/{user}/role', [UserController::class, 'updateRole'])->middleware('action.confirmed')->name('users.role');
        Route::patch('users/{user}/status', [UserController::class, 'updateStatus'])->middleware('action.confirmed')->name('users.status');
    });
});

require __DIR__.'/settings.php';

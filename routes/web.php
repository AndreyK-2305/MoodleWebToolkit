<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExecutionController;
use App\Http\Controllers\ExecutionEventController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectWizardController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn (Request $request) => redirect()->route($request->user() ? 'dashboard' : 'login'))->name('home');

Route::middleware(['auth', 'active', 'verified', 'password.changed'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('projects/{project:uuid}', [ProjectController::class, 'show'])->name('projects.show');
    Route::post('projects/{project:uuid}/executions', [ExecutionController::class, 'store'])->name('projects.executions.store');
    Route::get('projects/{project:uuid}/executions/{execution:uuid}', [ExecutionController::class, 'show'])->name('projects.executions.show');
    Route::get('projects/{project:uuid}/executions/{execution:uuid}/events', [ExecutionEventController::class, 'index'])->name('projects.executions.events');
    Route::patch('projects/{project:uuid}/wizard/basics', [ProjectWizardController::class, 'basics'])->name('projects.wizard.basics');
    Route::put('projects/{project:uuid}/wizard/instances', [ProjectWizardController::class, 'instances'])->name('projects.wizard.instances');
    Route::put('projects/{project:uuid}/wizard/options', [ProjectWizardController::class, 'options'])->name('projects.wizard.options');
    Route::post('projects/{project:uuid}/wizard/preflight', [ProjectWizardController::class, 'preflight'])->name('projects.wizard.preflight');
    Route::post('projects/{project:uuid}/wizard/confirm', [ProjectWizardController::class, 'confirm'])->name('projects.wizard.confirm');
    Route::inertia('manuals', 'manuals')->name('manuals.index');
    Route::inertia('about', 'about')->name('about');

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::patch('users/{user}/role', [UserController::class, 'updateRole'])->name('users.role');
        Route::patch('users/{user}/status', [UserController::class, 'updateStatus'])->name('users.status');
    });
});

require __DIR__.'/settings.php';

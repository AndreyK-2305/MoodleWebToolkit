<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\DashboardController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn (Request $request) => redirect()->route($request->user() ? 'dashboard' : 'login'))->name('home');

Route::middleware(['auth', 'active', 'verified', 'password.changed'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::inertia('projects', 'projects')->name('projects.index');
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

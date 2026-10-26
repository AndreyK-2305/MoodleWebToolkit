<?php

use App\Http\Controllers\AcademicProposalController;
use App\Http\Controllers\Admin\NotificationSettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ArtifactController;
use App\Http\Controllers\CollectorConfigurationController;
use App\Http\Controllers\ConfirmActionPasswordController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExecutionActionController;
use App\Http\Controllers\ExecutionController;
use App\Http\Controllers\ExecutionEventController;
use App\Http\Controllers\ExecutionReviewController;
use App\Http\Controllers\LinkedCollectionController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectWizardController;
use App\Http\Controllers\RemoteCollectionConfigurationController;
use App\Http\Controllers\RemoteConnectionController;
use App\Http\Controllers\RemoteConsolidationConfigurationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn (Request $request) => redirect()->route($request->user() ? 'dashboard' : 'login'))->name('home');

Route::middleware(['auth', 'active', 'verified', 'password.changed'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('projects', [ProjectController::class, 'store'])->middleware('action.confirmed')->name('projects.store');
    Route::get('projects/{project:uuid}', [ProjectController::class, 'show'])->name('projects.show');
    Route::put('projects/{project:uuid}/collector/configuration', CollectorConfigurationController::class)->middleware('action.confirmed')->name('projects.collector.configuration');
    Route::put('projects/{project:uuid}/collector/remote-configuration', RemoteCollectionConfigurationController::class)->middleware('action.confirmed')->name('projects.collector.remote-configuration');
    Route::put('projects/{project:uuid}/consolidation/remote-configuration', RemoteConsolidationConfigurationController::class)->middleware('action.confirmed')->name('projects.consolidation.remote-configuration');
    Route::post('projects/{project:uuid}/consolidation/linked-collection', LinkedCollectionController::class)->middleware('action.confirmed')->name('projects.consolidation.linked-collection');
    Route::put('projects/{project:uuid}/servers/{server:uuid}/connection', [RemoteConnectionController::class, 'update'])->middleware('action.confirmed')->name('projects.servers.connection.update');
    Route::post('projects/{project:uuid}/servers/{server:uuid}/connection/install-agent', [RemoteConnectionController::class, 'installAgent'])->middleware('action.confirmed')->name('projects.servers.connection.install-agent');
    Route::post('projects/{project:uuid}/servers/{server:uuid}/connection/update-agent', [RemoteConnectionController::class, 'updateAgent'])->middleware('action.confirmed')->name('projects.servers.connection.update-agent');
    Route::post('projects/{project:uuid}/servers/{server:uuid}/connection/test', [RemoteConnectionController::class, 'test'])->middleware('action.confirmed')->name('projects.servers.connection.test');
    Route::post('projects/{project:uuid}/servers/{server:uuid}/collector/deploy', [RemoteConnectionController::class, 'deployCollector'])->middleware('action.confirmed')->name('projects.servers.collector.deploy');
    Route::post('projects/{project:uuid}/servers/{server:uuid}/sources/check', [RemoteConnectionController::class, 'checkSource'])->middleware('action.confirmed')->name('projects.servers.sources.check');
    Route::post('projects/{project:uuid}/servers/{server:uuid}/sources/detect', [RemoteConnectionController::class, 'detectSources'])->middleware('action.confirmed')->name('projects.servers.sources.detect');
    Route::post('projects/{project:uuid}/servers/{server:uuid}/consolidator/deploy', [RemoteConnectionController::class, 'deployConsolidator'])->middleware('action.confirmed')->name('projects.servers.consolidator.deploy');
    Route::post('projects/{project:uuid}/servers/{server:uuid}/destinations/check', [RemoteConnectionController::class, 'checkDestination'])->middleware('action.confirmed')->name('projects.servers.destinations.check');
    Route::post('projects/{project:uuid}/servers/{server:uuid}/destinations/detect', [RemoteConnectionController::class, 'detectDestinations'])->middleware('action.confirmed')->name('projects.servers.destinations.detect');
    Route::post('projects/{project:uuid}/servers/{server:uuid}/destinations/prepare', [RemoteConnectionController::class, 'prepareDestination'])->middleware('action.confirmed')->name('projects.servers.destinations.prepare');
    Route::post('projects/{project:uuid}/servers/{server:uuid}/destinations/prepare/cancel', [RemoteConnectionController::class, 'cancelDestinationPreparation'])->middleware('action.confirmed')->name('projects.servers.destinations.prepare.cancel');
    Route::post('projects/{project:uuid}/servers/{server:uuid}/destinations/packages', [RemoteConnectionController::class, 'fetchDestinationPackage'])->middleware('action.confirmed')->name('projects.servers.destinations.packages.fetch');
    Route::post('projects/{project:uuid}/servers/{server:uuid}/destinations/packages/list', [RemoteConnectionController::class, 'listDestinationPackages'])->middleware('action.confirmed')->name('projects.servers.destinations.packages.list');
    Route::post('projects/{project:uuid}/servers/{server:uuid}/destinations/packages/remove', [RemoteConnectionController::class, 'removeDestinationPackage'])->middleware('action.confirmed')->name('projects.servers.destinations.packages.remove');
    Route::post('projects/{project:uuid}/executions', [ExecutionController::class, 'store'])->middleware('action.confirmed')->name('projects.executions.store');
    Route::get('projects/{project:uuid}/executions/{execution:uuid}', [ExecutionController::class, 'show'])->name('projects.executions.show');
    Route::get('projects/{project:uuid}/executions/{execution:uuid}/events', [ExecutionEventController::class, 'index'])->name('projects.executions.events');
    Route::post('projects/{project:uuid}/executions/{execution:uuid}/cancel', [ExecutionActionController::class, 'cancel'])->middleware('action.confirmed')->name('projects.executions.cancel');
    Route::post('projects/{project:uuid}/executions/{execution:uuid}/resume', [ExecutionActionController::class, 'resume'])->middleware('action.confirmed')->name('projects.executions.resume');
    Route::post('projects/{project:uuid}/executions/{execution:uuid}/consolidation/retry', [ExecutionActionController::class, 'retryConsolidation'])->middleware('action.confirmed')->name('projects.executions.consolidation.retry');
    Route::post('projects/{project:uuid}/executions/{execution:uuid}/consolidation/backup', [ExecutionActionController::class, 'backupConsolidation'])->middleware('action.confirmed')->name('projects.executions.consolidation.backup');
    Route::post('projects/{project:uuid}/executions/{execution:uuid}/fresh-export', [ExecutionActionController::class, 'freshExport'])->middleware('action.confirmed')->name('projects.executions.fresh-export');
    Route::post('projects/{project:uuid}/executions/{execution:uuid}/proposals', [AcademicProposalController::class, 'store'])->middleware('action.confirmed')->name('projects.executions.proposals.store');
    Route::post('projects/{project:uuid}/executions/{execution:uuid}/validate', [ExecutionReviewController::class, 'validateExecution'])->middleware('action.confirmed')->name('projects.executions.validate');
    Route::post('projects/{project:uuid}/executions/{execution:uuid}/finalize', [ExecutionReviewController::class, 'finalize'])->middleware('action.confirmed')->name('projects.executions.finalize');
    Route::get('projects/{project:uuid}/executions/{execution:uuid}/artifacts/{artifact}/download', [ArtifactController::class, 'download'])->name('projects.executions.artifacts.download');
    Route::post('projects/{project:uuid}/executions/{execution:uuid}/conflicts/{conflict}/plugin-inspect', [ExecutionActionController::class, 'inspectPlugin'])->middleware('action.confirmed')->name('projects.executions.conflicts.plugin-inspect');
    Route::post('projects/{project:uuid}/executions/{execution:uuid}/conflicts/{conflict}/answers', [ExecutionActionController::class, 'answerIntervention'])->middleware('action.confirmed')->name('projects.executions.conflicts.answers');
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
        Route::get('notifications', [NotificationSettingsController::class, 'edit'])->name('notifications.edit');
        Route::put('notifications', [NotificationSettingsController::class, 'update'])->middleware('action.confirmed')->name('notifications.update');
        Route::put('notifications/recipients', [NotificationSettingsController::class, 'updateRecipients'])->middleware('action.confirmed')->name('notifications.recipients.update');
        Route::post('notifications/test', [NotificationSettingsController::class, 'test'])->middleware(['action.confirmed', 'throttle:6,1'])->name('notifications.test');
    });
});

require __DIR__.'/settings.php';

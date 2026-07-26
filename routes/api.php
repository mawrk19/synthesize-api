<?php

use App\Modules\Analysis\Http\Controllers\AnalysisController;
use App\Modules\Authorization\Http\Controllers\PermissionController;
use App\Modules\Authorization\Http\Controllers\RoleController;
use App\Modules\Collaboration\Http\Controllers\CollaborationController;
use App\Modules\Core\Http\Controllers\AiUsageController;
use App\Modules\Core\Http\Controllers\ContextController;
use App\Modules\Diagrams\Http\Controllers\DiagramController;
use App\Modules\Documents\Http\Controllers\SrsDocumentController;
use App\Modules\Iam\Http\Controllers\AuthController;
use App\Modules\Iam\Http\Controllers\UserController;
use App\Modules\Orchestration\Http\Controllers\PipelineController;
use App\Modules\Projects\Http\Controllers\ContextFileController;
use App\Modules\Projects\Http\Controllers\IntakeSessionController;
use App\Modules\Projects\Http\Controllers\ProjectController;
use App\Modules\Projects\Http\Controllers\RequirementController;
use Illuminate\Support\Facades\Route;

Route::get('/context', [ContextController::class, 'index'])->name('context.index')->middleware('auth:sanctum');
Route::get('/dashboard/ai-usage', [AiUsageController::class, 'summary'])
    ->name('dashboard.ai-usage')
    ->middleware('auth:sanctum');

Route::group(['prefix' => '/iam', 'as' => 'iam.', 'middleware' => 'auth:sanctum'], function () {
    Route::get('/current', [AuthController::class, 'currentUser'])->name('user.current');
    Route::post('/login', [AuthController::class, 'login'])->name('login')->withoutMiddleware('auth:sanctum');
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
});

Route::group(['prefix' => '/authorization', 'as' => 'authorization.', 'middleware' => 'auth:sanctum'], function () {
    Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
    Route::get('/roles/{id}', [RoleController::class, 'show'])->name('roles.show');
    Route::get('/roles/{id}/users', [RoleController::class, 'users'])->name('roles.users');
    Route::post('/roles/{id}/users', [RoleController::class, 'addUser'])->name('roles.add-user');
    Route::put('/roles/{id}/permissions', [RoleController::class, 'updatePermissions'])->name('roles.update-permissions');

    Route::get('/permissions', [PermissionController::class, 'index'])->name('permissions.index');
});

Route::group(['prefix' => '/documents', 'as' => 'documents.', 'middleware' => 'auth:sanctum'], function () {
    Route::get('/', [SrsDocumentController::class, 'index'])->name('index');
    Route::post('/', [SrsDocumentController::class, 'store'])->name('store');
    Route::get('/{id}', [SrsDocumentController::class, 'show'])->name('show');
    Route::patch('/{id}', [SrsDocumentController::class, 'update'])->name('update');
    Route::delete('/{id}', [SrsDocumentController::class, 'destroy'])->name('destroy');
    Route::post('/{id}/regenerate', [SrsDocumentController::class, 'regenerate'])->name('regenerate');

    Route::post('/{id}/generate-diagrams', [DiagramController::class, 'generateFromDocument'])->name('generate-diagrams');
    Route::post('/{id}/analyze/gap', [AnalysisController::class, 'gap'])->name('analyze.gap');
    Route::post('/{id}/analyze/validate', [AnalysisController::class, 'validateRequirements'])->name('analyze.validate');
    Route::post('/{id}/generate-schema', [AnalysisController::class, 'schema'])->name('generate-schema');
    Route::get('/{id}/analysis-runs', [AnalysisController::class, 'runs'])->name('analysis-runs');
    Route::get('/{id}/schemas', [AnalysisController::class, 'schemas'])->name('schemas');
    Route::get('/{id}/export/prd', [AnalysisController::class, 'exportPrd'])->name('export.prd');
    Route::get('/{id}/export/readme', [AnalysisController::class, 'exportReadme'])->name('export.readme');
    Route::get('/{id}/versions', [CollaborationController::class, 'listVersions'])->name('versions');
    Route::post('/{id}/versions/{versionId}/restore', [CollaborationController::class, 'restoreVersion'])->name('versions.restore');
    Route::post('/{id}/pipeline/start', [PipelineController::class, 'startFromDocument'])->name('pipeline.start');
});

Route::group(['prefix' => '/projects', 'as' => 'projects.', 'middleware' => 'auth:sanctum'], function () {
    Route::get('/', [ProjectController::class, 'index'])->name('index');
    Route::post('/', [ProjectController::class, 'store'])->name('store');
    Route::get('/{id}', [ProjectController::class, 'show'])->name('show');
    Route::patch('/{id}', [ProjectController::class, 'update'])->name('update');
    Route::delete('/{id}', [ProjectController::class, 'destroy'])->name('destroy');

    Route::get('/{projectId}/context-files', [ContextFileController::class, 'index'])->name('context-files.index');
    Route::post('/{projectId}/context-files', [ContextFileController::class, 'store'])->name('context-files.store');
    Route::get('/{projectId}/context-files/{id}', [ContextFileController::class, 'show'])->name('context-files.show');
    Route::post('/{projectId}/context-files/{id}/reextract', [ContextFileController::class, 'reextract'])->name('context-files.reextract');
    Route::delete('/{projectId}/context-files/{id}', [ContextFileController::class, 'destroy'])->name('context-files.destroy');

    Route::get('/{projectId}/intake-sessions', [IntakeSessionController::class, 'index'])->name('intake.index');
    Route::post('/{projectId}/intake-sessions', [IntakeSessionController::class, 'store'])->name('intake.store');
    Route::get('/{projectId}/intake-sessions/{id}', [IntakeSessionController::class, 'show'])->name('intake.show');
    Route::patch('/{projectId}/intake-sessions/{id}', [IntakeSessionController::class, 'update'])->name('intake.update');
    Route::delete('/{projectId}/intake-sessions/{id}', [IntakeSessionController::class, 'destroy'])->name('intake.destroy');
    Route::post('/{projectId}/intake-sessions/{id}/structure', [IntakeSessionController::class, 'structure'])->name('intake.structure');
    Route::post('/{projectId}/intake-sessions/{id}/generate-srs', [IntakeSessionController::class, 'generateSrs'])->name('intake.generate-srs');
    Route::post('/{projectId}/transcripts', [IntakeSessionController::class, 'storeTranscript'])->name('transcripts.store');

    Route::get('/{projectId}/requirements', [RequirementController::class, 'index'])->name('requirements.index');
    Route::get('/{projectId}/requirements/{id}', [RequirementController::class, 'show'])->name('requirements.show');
    Route::post('/{projectId}/requirements/{id}/clear-flags', [RequirementController::class, 'clearValidationFlags'])->name('requirements.clear-flags');

    Route::get('/{projectId}/diagrams', [DiagramController::class, 'index'])->name('diagrams.index');
    Route::post('/{projectId}/diagrams', [DiagramController::class, 'store'])->name('diagrams.store');
    Route::get('/{projectId}/diagrams/{id}', [DiagramController::class, 'show'])->name('diagrams.show');
    Route::patch('/{projectId}/diagrams/{id}', [DiagramController::class, 'update'])->name('diagrams.update');
    Route::delete('/{projectId}/diagrams/{id}', [DiagramController::class, 'destroy'])->name('diagrams.destroy');
    Route::post('/{projectId}/diagrams/{id}/generate', [DiagramController::class, 'generate'])->name('diagrams.generate');

    Route::get('/{projectId}/review-links', [CollaborationController::class, 'listReviewLinks'])->name('review-links.index');
    Route::post('/{projectId}/review-links', [CollaborationController::class, 'storeReviewLink'])->name('review-links.store');
    Route::delete('/{projectId}/review-links/{id}', [CollaborationController::class, 'destroyReviewLink'])->name('review-links.destroy');

    Route::get('/{projectId}/pipeline-runs', [PipelineController::class, 'listRuns'])->name('pipeline-runs.index');
    Route::post('/{projectId}/pipeline-runs/{runId}/approve', [PipelineController::class, 'approve'])->name('pipeline-runs.approve');
    Route::post('/{projectId}/pipeline-runs/{runId}/cancel', [PipelineController::class, 'cancel'])->name('pipeline-runs.cancel');
    Route::get('/{projectId}/repository', [PipelineController::class, 'showRepository'])->name('repository.show');
    Route::put('/{projectId}/repository', [PipelineController::class, 'upsertRepository'])->name('repository.upsert');
});

Route::get('/analysis-runs/{runId}', [AnalysisController::class, 'showRun'])->middleware('auth:sanctum');
Route::get('/schema-artifacts/{schemaId}', [AnalysisController::class, 'showSchema'])->middleware('auth:sanctum');
Route::get('/pipeline-runs/{runId}', [PipelineController::class, 'showRun'])->middleware('auth:sanctum');
Route::get('/pipeline-tasks/{taskId}', [PipelineController::class, 'showTask'])->middleware('auth:sanctum');

Route::get('/requirements/{requirementId}/comments', [CollaborationController::class, 'listComments'])->middleware('auth:sanctum');
Route::post('/requirements/{requirementId}/comments', [CollaborationController::class, 'storeComment'])->middleware('auth:sanctum');
Route::patch('/comments/{commentId}/resolve', [CollaborationController::class, 'resolveComment'])->middleware('auth:sanctum');

Route::get('/review/{token}', [CollaborationController::class, 'showReview']);
Route::post('/review/{token}/requirements/{requirementId}/comments', [CollaborationController::class, 'storeGuestComment']);
Route::post('/review/{token}/approve-pipeline', [CollaborationController::class, 'approvePipeline']);

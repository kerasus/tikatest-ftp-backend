<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FileActivityLogController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\SanjadConnectorController;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\VerifySanjadRequest;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Public routes (no authentication required)
// Route::get('test', [AuthController::class, 'test']);
// Route::post('register', [AuthController::class, 'register']);
Route::post('login', [AuthController::class, 'login']);

Route::get('clear', [AuthController::class, 'clear']);

// Protected routes (require authentication via Sanctum)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/files/browse', [FileController::class, 'browse']);
    Route::get('/files/options', [FileController::class, 'getOptions']);
    Route::post('/files/cancel', [FileController::class, 'cancel']);
    Route::patch('/files/rename', [FileController::class, 'rename']);
    Route::post('/files/move', [FileController::class, 'move']);
    Route::post('/files/cache/browse/clear', [FileController::class, 'clearBrowseCache']);
    Route::post('/files/cache/options/clear', [FileController::class, 'clearOptionsCache']);
    Route::delete('/files', [FileController::class, 'delete']);

    // مسیر آپلود: محدودیت را برداریم یا خیلی زیاد کنیم
    // 'throttle:60,1' یعنی ۶۰ درخواست در هر دقیقه (برای آپلود چانکی کم است)
    // پس از میدلور throttle استفاده نمی‌کنیم
    Route::post('/files/upload', [FileController::class, 'upload'])
        ->middleware('throttle:file-upload');

    Route::apiResource('users', '\\'.UserController::class);
    Route::apiResource('permissions', '\\'.PermissionController::class);
    Route::post('users/{user_id}/reset-password', [AuthController::class, 'resetPassword'])->name('users.reset.password');
    Route::post('users/{user}/assign-role', [AuthController::class, 'assignRole']);
    Route::post('users/{user}/remove-role', [AuthController::class, 'removeRole']);
    Route::get('/users/{user}/permissions', [PermissionController::class, 'userPermissions']);
    Route::post('/users/{user}/permissions', [PermissionController::class, 'grantUserPermission']);
    Route::delete('/users/{user}/permissions', [PermissionController::class, 'revokeUserPermission']);

    Route::get('file-activity-logs', [FileActivityLogController::class, 'index']);
    Route::get('file-activity-logs/{fileActivityLog}', [FileActivityLogController::class, 'show']);

    Route::get('/sanjad/classes', [SanjadConnectorController::class, 'getClasses']);
    Route::get('/sanjad/classes/database', [SanjadConnectorController::class, 'index']);
    Route::post('/sanjad/classes/sync', [SanjadConnectorController::class, 'syncClasses']);
    Route::get('/sanjad/classes/{sanjadClass}/permissions', [SanjadConnectorController::class, 'classPermissions']);
    Route::post('/sanjad/classes/{sanjadClass}/permissions', [SanjadConnectorController::class, 'grantClassPermission']);
    Route::delete('/sanjad/classes/{sanjadClass}/permissions', [SanjadConnectorController::class, 'revokeClassPermission']);
});

// روت ورودی که سرور سنجاد برای گرفتن فایل‌های یک کلاس صدا می‌زند (محافظت شده با میدلور اختصاصی)
Route::middleware(VerifySanjadRequest::class)->prefix('sanjad')->group(function () {
    Route::get('/classes/{classId}/files', [SanjadConnectorController::class, 'getFilesByClassId']);
    Route::post('/classes/files-tree', [SanjadConnectorController::class, 'getFilesByClassIds']);
});

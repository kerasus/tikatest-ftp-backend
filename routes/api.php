<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\PermissionController;

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
//Route::get('test', [AuthController::class, 'test']);
//Route::post('register', [AuthController::class, 'register']);
Route::post('login', [AuthController::class, 'login']);

Route::get('clear', [AuthController::class, 'clear']);

// Protected routes (require authentication via Sanctum)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/files/browse', [FileController::class, 'browse']);
    Route::get('/files/options', [FileController::class, 'getOptions']);
    Route::post('/files/cancel', [FileController::class, 'cancel']);
    Route::patch('/files/rename', [FileController::class, 'rename']);
    Route::post('/files/cache/browse/clear', [FileController::class, 'clearBrowseCache']);
    Route::post('/files/cache/options/clear', [FileController::class, 'clearOptionsCache']);
    Route::delete('/files', [FileController::class, 'delete']);


    // مسیر آپلود: محدودیت را برداریم یا خیلی زیاد کنیم
    // 'throttle:60,1' یعنی ۶۰ درخواست در هر دقیقه (برای آپلود چانکی کم است)
    // پس از میدلور throttle استفاده نمی‌کنیم
    Route::post('/files/upload', [FileController::class, 'upload'])->withoutMiddleware([\App\Http\Middleware\ThrottleRequests::class]);

    Route::apiResource('users', '\\'.UserController::class);
    Route::apiResource('permissions', '\\'.PermissionController::class);
    Route::post('users/{user_id}/reset-password', [AuthController::class, 'resetPassword'])->name('users.reset.password');
    Route::post('users/{user}/assign-role', [AuthController::class, 'assignRole']);
    Route::post('users/{user}/remove-role', [AuthController::class, 'removeRole']);
    Route::get(
        '/users/{user}/permissions',
        [PermissionController::class, 'userPermissions']
    );

    Route::post(
        '/users/{user}/permissions',
        [PermissionController::class, 'grantUserPermission']
    );

    Route::delete(
        '/users/{user}/permissions',
        [PermissionController::class, 'revokeUserPermission']
    );
});

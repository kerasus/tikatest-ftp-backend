<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Enums\UserRole;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Role;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;


class AuthController extends Controller
{
    use ApiResponse;

    public function __construct()
    {
        $this->middleware('role:admin')->only(['resetPassword']);
    }

    /**
     * Register a new user.
     */
    public function register(Request $request): JsonResponse
    {
        $validator = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|unique:users',
            'password' => 'required|string|min:8',
            'mobile' => 'required|string|unique:users',
            'email' => 'nullable|string|email|unique:users',
        ]);


        if ($validator->fails()) {
            return $this->respondWithValidationErrors($validator);
        }

        $user = User::create([
            'name' => $request->name,
            'username' => $request->username,
            'mobile' => $request->mobile,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        return response()->json([
            'message' => 'User registered successfully',
            'user' => $user,
        ], 201);
    }

    /**
     * Authenticate a user and issue a token.
     */
    public function login(Request $request): JsonResponse
    {
        $validator = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('username', $request->username)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Issue a token
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function clear(): JsonResponse
    {
        // ۱. مسیر کامل فایل لوکال (برای اطمینان)
        $localFile = base_path('test.txt'); // فرض می‌کنیم در روت پروژه است

        // ۲. چک کن فایل وجود دارد یا نه
        if (!file_exists($localFile)) {
            return response()->json(['error' => 'File not found locally: ' . $localFile], 404);
        }

        $ftp = @ftp_connect('194.147.142.36', 21, 10);
        if (!$ftp) return response()->json(['error' => 'Could not connect to FTP'], 500);

        $login = @ftp_login($ftp, 'pz14533', 'iXSz4dDh');
        if (!$login) return response()->json(['error' => 'FTP Login failed'], 500);

        ftp_pasv($ftp, true);

        // ۳. آپلود با مسیر مطلق (اگر روت / است، همان test.txt کافیست)
        if (@ftp_put($ftp, 'test.txt', $localFile, FTP_BINARY)) {
            ftp_close($ftp);
            return response()->json(['message' => 'Upload successful']);
        } else {
            $error = error_get_last();
            ftp_close($ftp);
            return response()->json(['error' => 'Upload failed', 'details' => $error], 500);
        }

//        dd(Storage::disk('remote_ftp')->directories('/'));
//
//        shell_exec('php artisan config:clear');
//        shell_exec('php artisan cache:clear');
//        shell_exec('php artisan config:cache');

        return response()->json([
            'message' => 'cache cleared'
        ]);
    }

    /**
     * Send a verification code to the authenticated user's mobile number.
     */
    public function sendVerificationCode(Request $request)
    {
        $user = $request->user(); // Get the authenticated user

        // Generate a random 6-digit verification code
        $verificationCode = mt_rand(100000, 999999);

        // Update the user's record with the verification code
        $user->update([
            'mobile_verification_code' => $verificationCode,
        ]);

        // Simulate sending the verification code (e.g., via SMS gateway)
//        Log::info("Verification code for {$user->mobile}: {$verificationCode}");

        return response()->json([
            'message' => 'Verification code sent successfully.',
        ]);
    }

    /**
     * Verify the user's mobile number using the verification code.
     */
    public function verifyMobile(Request $request)
    {
        $validator = $request->validate([
            'mobile' => 'required|string',
            'code' => 'required|string',
        ]);


        if ($validator->fails()) {
            return $this->respondWithValidationErrors($validator);
        }

        $user = User::where('mobile', $request->mobile)->first();

        if (!$user || $user->mobile_verification_code !== $request->code) {
            return response()->json([
                'message' => 'Invalid verification code.',
            ], 400);
        }

        // Mark the mobile as verified
        $user->update([
            'mobile_verified_at' => now(),
            'mobile_verification_code' => null, // Clear the verification code
        ]);

        return response()->json([
            'message' => 'Mobile number verified successfully.',
            'user' => $user,
        ]);
    }

    /**
     * Logout the user and revoke the token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    /**
     * Reset a user's password by ID.
     *
     * @param Request $request
     * @param int $user_id
     * @return JsonResponse
     */
    public function resetPassword(Request $request, int $user_id): JsonResponse
    {
        $request->validate([
            'new_password' => 'required|string|confirmed',
        ]);

        // Find the user by ID
        $user = User::findOrFail($user_id);

        // Update the user's password
        $user->update([
            'password' => Hash::make($request->input('new_password')),
        ]);

        return response()->json([
            'message' => 'کلمه عبور با موفقیت تغییر یافت.',
        ], 200);
    }

    /**
     * اختصاص دادن یک نقش جدید به کاربر بدون حذف نقش‌های قبلی
     */
    public function assignRole(Request $request, User $user)
    {
        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in(UserRole::names())],
        ]);

        $role = Role::findByName($validated['role'], 'web');

        // نقش جدید اضافه می‌شود و نقش‌های قبلی حفظ می‌شوند
        $user->assignRole($role);

        return response()->json([
            'message' => 'نقش با موفقیت به کاربر اختصاص یافت.',
            'user' => [
                'id' => $user->id,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'roles' => $user->fresh()->getRoleNames()->values(),
                'roles_list' => $user->fresh()->roles_list,
            ],
        ]);
    }


    public function removeRole(Request $request, User $user)
    {
        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in(UserRole::names())],
        ]);

        $role = Role::findByName($validated['role'], 'web');

        $user->removeRole($role);

        $freshUser = $user->fresh();

        return response()->json([
            'message' => 'نقش با موفقیت از کاربر حذف شد.',
            'user' => [
                'id' => $freshUser->id,
                'firstname' => $freshUser->firstname,
                'lastname' => $freshUser->lastname,
                'roles' => $freshUser->getRoleNames()->values(),
                'roles_list' => $freshUser->roles_list,
            ],
        ]);
    }
}

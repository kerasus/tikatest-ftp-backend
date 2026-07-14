<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Traits\Filter;
use App\Enums\UserRole;
use App\Traits\CommonCRUD;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;

class UserController extends Controller
{
    use Filter, CommonCRUD, ApiResponse;

    public function __construct()
    {
        // Apply authorization middleware if needed
        $this->middleware('auth:sanctum');
        $this->middleware('role:admin')->only(['store', 'update', 'destroy', 'assignRole', 'removeRole']);
        $this->middleware(function ($request, $next) {
            $user = $request->user();

            if (!$user->hasAnyRole([
                UserRole::ADMIN,
                UserRole::TEACHER,
                UserRole::SUPPORT
            ])) {
                return $this->respondForbidden('دسترسی غیرمجاز');
            }

            return $next($request);
        })->only(['index', 'show']);
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $config = [
            'filterKeys' => [
                'firstname',
                'lastname',
                'username',
                'email',
                'mobile'
            ],
            'filterOnMultipleColumnKeys' => [
                [
                    'requestKey' => 'full_name',
                    'columns' => [
                        'firstname',
                        'lastname',
                    ]
                ],
            ],
            'scopes' => [
                'role'
            ]
        ];

        return $this->commonIndex($request, User::class, $config);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'firstname' => 'required|string|max:255',
            'lastname' => 'required|string|max:255',
            'username' => 'required|string|unique:users',
            'mobile' => 'required|string|unique:users',
            'email' => 'nullable|string|email|unique:users',
            'password' => 'required|string|min:6',
        ]);

        return $this->commonStore($request, User::class);
    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $permissions = $user->getAllPermissions();

        $userData = $user->toArray();
        $userData['permissions'] = $permissions;

        return $this->respondWithSuccess($userData);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param Request $request
     * @param User $user
     * @return JsonResponse
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'username' => 'sometimes|required|string|unique:users,username,' . $user->id,
            'mobile' => 'sometimes|required|string|unique:users,mobile,' . $user->id,
            'email' => 'nullable|string|email|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:8',
        ]);

        return $this->commonUpdate($request, $user);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param User $user
     * @return JsonResponse
     */
    public function destroy(User $user): JsonResponse
    {
        return $this->commonDestroy($user);
    }

    /**
     * Assign a role to a user.
     *
     * @param Request $request
     * @param int $userId
     * @return JsonResponse
     */
    public function assignRole(Request $request, int $userId): JsonResponse
    {
        try {
            // Validate the request data
            $request->validate([
                'role' => 'required|string|exists:roles,name', // Ensure the role exists in the database
            ]);

            // Find the user by ID
            $user = User::findOrFail($userId);

            // Assign the role to the user
            $user->assignRole($request->input('role'));

            return $this->respondWithSuccess(
                [
                    'user' => $user,
                    'roles' => $user->getRoleNames(), // Get all roles assigned to the user
                ]
            );
        } catch (\Exception $e) {
            return $this->respondWithError(
                'An unexpected error occurred while assigning the role.',
                ['detail' => $e->getMessage()],
                ResponseAlias::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Remove a role from a user.
     *
     * @param Request $request
     * @param int $userId
     * @return JsonResponse
     */
    public function removeRole(Request $request, int $userId): JsonResponse
    {
        try {
            // Validate the request data
            $request->validate([
                'role' => 'required|string|exists:roles,name', // Ensure the role exists in the database
            ]);

            // Find the user by ID
            $user = User::findOrFail($userId);

            // Remove the role from the user
            $user->removeRole($request->input('role'));

            return $this->respondWithSuccess(
                [
                    'user' => $user,
                    'roles' => $user->getRoleNames(), // Get all roles assigned to the user
                ]
            );
        } catch (\Exception $e) {
            return $this->respondWithError(
                'An unexpected error occurred while removing the role.',
                ['detail' => $e->getMessage()],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}

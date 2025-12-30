<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\Rule;


class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
            'phone' => ['nullable', 'string', 'max:20'],
            'company_name' => ['required', 'string', 'max:255'],
            'company_email' => ['nullable', 'email', 'max:255'],
            'company_phone' => ['nullable', 'string', 'max:20'],
            'company_address' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            Log::warning('Registration validation failed', [
                'email' => $request->email,
                'errors' => $validator->errors()
            ]);

            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Create company first
            $company = Company::create([
                'name' => $request->company_name,
                'email' => $request->company_email,
                'phone' => $request->company_phone,
                'address' => $request->company_address,
                'active' => true,
                'trial_ends_at' => now()->addDays(30), // 30-day trial
            ]);

            // Create user as company owner
            $user = User::create([
                'company_id' => $company->id,
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'email' => $request->email,
                'password' => $request->password,
                'phone' => $request->phone,
                'role' => 'owner',
                'active' => true,
            ]);

            Log::info('User created successfully', ['user_id' => $user->id]);

            // Create API token
            $token = $user->createToken('auth_token')->plainTextToken;

            DB::commit();

            Log::info('User registration completed', [
                'user_id' => $user->id,
                'company_id' => $company->id
            ]);

            return response()->json([
                'message' => 'Registration successful',
                'user' => $user->load('company'),
                'token' => $token,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Registration failed', [
                'email' => $request->email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Registration failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if ($validator->fails()) {
            Log::warning('Login validation failed', [
                'email' => $request->email,
                'errors' => $validator->errors()
            ]);

            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $credentials = $request->only(['email', 'password']);

        if (!Auth::attempt($credentials)) {
            Log::warning('Login failed - invalid credentials', ['email' => $request->email]);

            return response()->json([
                'message' => 'Invalid credentials'
            ], 401);
        }

        $user = Auth::user();

        if (!$user->hasCompanyAccess()) {
            Log::warning('Login denied - user or company inactive', [
                'user_id' => $user->id,
                'user_active' => $user->active,
                'company_active' => $user->company->active
            ]);

            return response()->json([
                'message' => 'Account is inactive. Please contact support.'
            ], 403);
        }

        // Create API token
        $token = $user->createToken('auth_token')->plainTextToken;

        Log::info('Login successful', ['user_id' => $user->id]);

        return response()->json([
            'message' => 'Login successful',
            'user' => $user->load('company'),
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $user = $request->user();

        Log::info('User logout from all devices', ['user_id' => $user->id]);

        // Revoke all tokens for this user
        $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'Logged out from all devices successfully'
        ]);
    }

    public function getUserProfile(Request $request)
    {
        $user = $request->user()->load('company');

        Log::info('Profile accessed', ['user_id' => $user->id]);

        return response()->json([
            'user' => $user
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'email',
                'max:255',
                Rule::unique('users')->ignore($user->id)
            ],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        if ($validator->fails()) {
            Log::warning('Profile update validation failed', [
                'user_id' => $user->id,
                'errors' => $validator->errors()
            ]);

            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user->update($validator->validated());

            Log::info('Profile updated successfully', ['user_id' => $user->id]);

            return response()->json([
                'message' => 'Profile updated successfully',
                'user' => $user->fresh()->load('company')
            ]);

        } catch (\Exception $e) {
            Log::error('Profile update failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Profile update failed. Please try again.'
            ], 500);
        }
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        Log::info('Password reset requested', ['email' => $request->email]);

        // Validate forgot password data
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', 'exists:users,email'],
        ], [
            'email.exists' => 'We could not find a user with that email address.',
        ]);

        if ($validator->fails()) {
            Log::warning('Forgot password validation failed', [
                'email' => $request->email,
                'errors' => $validator->errors()
            ]);

            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status === Password::RESET_LINK_SENT) {
            Log::info('Password reset link sent', ['email' => $request->email]);

            return response()->json([
                'message' => 'Password reset link sent to your email'
            ]);
        }

        Log::warning('Password reset failed', [
            'email' => $request->email,
            'status' => $status
        ]);

        return response()->json([
            'message' => 'Unable to send password reset link'
        ], 500);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        if ($validator->fails()) {
            Log::warning('Reset password validation failed', [
                'email' => $request->email,
                'errors' => $validator->errors()
            ]);

            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password)
                ])->setRememberToken(Str::random(60));

                $user->save();

                event(new PasswordReset($user));

                Log::info('Password reset successful', ['user_id' => $user->id]);
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Password reset successful'
            ]);
        }

        Log::warning('Password reset failed', [
            'email' => $request->email,
            'status' => $status
        ]);

        return response()->json([
            'message' => 'Password reset failed'
        ], 400);
    }

}

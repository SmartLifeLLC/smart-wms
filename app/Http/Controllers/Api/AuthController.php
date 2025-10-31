<?php

namespace App\Http\Controllers\Api;

use App\Models\WmsPicker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends ApiController
{
    /**
     * Login with code and password
     *
     * @OA\Post(
     *     path="/api/auth/login",
     *     tags={"Authentication"},
     *     summary="Login to WMS",
     *     description="Authenticate picker with code and password, returns API token",
     *     security={{"apiKey":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="application/x-www-form-urlencoded",
     *             @OA\Schema(
     *                 required={"code","password"},
     *                 @OA\Property(property="code", type="string", example="TEST001"),
     *                 @OA\Property(property="password", type="string", example="password123"),
     *                 @OA\Property(property="device_id", type="string", example="ANDROID-12345")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful login",
     *         @OA\JsonContent(
     *             @OA\Property(property="code", type="string", example="LOGIN_SUCCESS"),
     *             @OA\Property(
     *                 property="result",
     *                 type="object",
     *                 @OA\Property(
     *                     property="data",
     *                     type="object",
     *                     @OA\Property(property="token", type="string", example="1|abcdef123456..."),
     *                     @OA\Property(
     *                         property="picker",
     *                         type="object",
     *                         @OA\Property(property="id", type="integer", example=2),
     *                         @OA\Property(property="code", type="string", example="TEST001"),
     *                         @OA\Property(property="name", type="string", example="テストピッカー"),
     *                         @OA\Property(property="default_warehouse_id", type="integer", example=991)
     *                     )
     *                 ),
     *                 @OA\Property(property="message", type="string", example="Login successful"),
     *                 @OA\Property(property="debug_message", type="string", nullable=true, example=null)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Invalid credentials or inactive account",
     *         @OA\JsonContent(
     *             @OA\Property(property="code", type="string", example="UNAUTHORIZED"),
     *             @OA\Property(
     *                 property="result",
     *                 type="object",
     *                 @OA\Property(property="data", type="object", nullable=true, example=null),
     *                 @OA\Property(property="error_message", type="string", example="Invalid credentials")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="code", type="string", example="VALIDATION_ERROR"),
     *             @OA\Property(
     *                 property="result",
     *                 type="object",
     *                 @OA\Property(property="data", type="object", nullable=true, example=null),
     *                 @OA\Property(property="error_message", type="string", example="Validation failed"),
     *                 @OA\Property(
     *                     property="errors",
     *                     type="object",
     *                     @OA\Property(property="code", type="array", @OA\Items(type="string", example="validation.required")),
     *                     @OA\Property(property="password", type="array", @OA\Items(type="string", example="validation.required"))
     *                 )
     *             )
     *         )
     *     )
     * )
     */
    public function login(Request $request): JsonResponse
    {
        $validator = validator($request->all(), [
            'code' => 'required|string',
            'password' => 'required|string',
            'device_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $picker = WmsPicker::where('code', $request->code)->first();

        if (!$picker || !Hash::check($request->password, $picker->password)) {
            return $this->unauthorized('Invalid credentials');
        }

        if (!$picker->is_active) {
            return $this->unauthorized('Account is not active');
        }

        // Create token with device_id if provided
        $tokenName = $request->device_id ?? 'api-token';
        $token = $picker->createToken($tokenName)->plainTextToken;

        return $this->success([
            'token' => $token,
            'picker' => [
                'id' => $picker->id,
                'code' => $picker->code,
                'name' => $picker->name,
                'default_warehouse_id' => $picker->default_warehouse_id,
            ],
        ], 'Login successful', 200, 'LOGIN_SUCCESS');
    }

    /**
     * Logout and revoke token
     *
     * @OA\Post(
     *     path="/api/auth/logout",
     *     tags={"Authentication"},
     *     summary="Logout from WMS",
     *     description="Revoke current API token",
     *     security={{"apiKey":{},"sanctum":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Successfully logged out",
     *         @OA\JsonContent(
     *             @OA\Property(property="code", type="string", example="LOGOUT_SUCCESS"),
     *             @OA\Property(
     *                 property="result",
     *                 type="object",
     *                 @OA\Property(property="data", type="object", nullable=true, example=null),
     *                 @OA\Property(property="message", type="string", example="Logged out successfully"),
     *                 @OA\Property(property="debug_message", type="string", nullable=true, example=null)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthenticated",
     *         @OA\JsonContent(
     *             @OA\Property(property="code", type="string", example="UNAUTHORIZED"),
     *             @OA\Property(
     *                 property="result",
     *                 type="object",
     *                 @OA\Property(property="data", type="object", nullable=true, example=null),
     *                 @OA\Property(property="error_message", type="string", example="Unauthenticated")
     *             )
     *         )
     *     )
     * )
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success(null, 'Logged out successfully', 200, 'LOGOUT_SUCCESS');
    }

    /**
     * Get current authenticated picker information
     *
     * @OA\Get(
     *     path="/api/me",
     *     tags={"Authentication"},
     *     summary="Get current picker info",
     *     description="Returns information about the authenticated picker",
     *     security={{"apiKey":{},"sanctum":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Picker information",
     *         @OA\JsonContent(
     *             @OA\Property(property="code", type="string", example="SUCCESS"),
     *             @OA\Property(
     *                 property="result",
     *                 type="object",
     *                 @OA\Property(
     *                     property="data",
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=2),
     *                     @OA\Property(property="code", type="string", example="TEST001"),
     *                     @OA\Property(property="name", type="string", example="テストピッカー"),
     *                     @OA\Property(property="default_warehouse_id", type="integer", example=991)
     *                 ),
     *                 @OA\Property(property="debug_message", type="string", nullable=true, example=null)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthenticated",
     *         @OA\JsonContent(
     *             @OA\Property(property="code", type="string", example="UNAUTHORIZED"),
     *             @OA\Property(
     *                 property="result",
     *                 type="object",
     *                 @OA\Property(property="data", type="object", nullable=true, example=null),
     *                 @OA\Property(property="error_message", type="string", example="Unauthenticated")
     *             )
     *         )
     *     )
     * )
     */
    public function me(Request $request): JsonResponse
    {
        $picker = $request->user();

        return $this->success([
            'id' => $picker->id,
            'code' => $picker->code,
            'name' => $picker->name,
            'default_warehouse_id' => $picker->default_warehouse_id,
        ], null, 200, 'SUCCESS');
    }
}

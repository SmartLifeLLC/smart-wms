<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @OA\Info(
 *     title="Smart WMS API",
 *     version="1.0.0",
 *     description="Warehouse Management System API for Android picking terminals"
 * )
 *
 * @OA\Server(
 *     url=L5_SWAGGER_CONST_HOST,
 *     description="API Server"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="apiKey",
 *     type="apiKey",
 *     in="header",
 *     name="X-API-Key",
 *     description="API Key for WMS API access (required for all endpoints)"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="sanctum",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="token",
 *     description="Bearer token from /auth/login endpoint"
 * )
 */
class ApiController extends Controller
{
    /**
     * Return a success JSON response
     *
     * @param mixed $data Response data (array, object, or paginator)
     * @param string|null $message Optional message
     * @param int $httpStatus HTTP status code
     * @param string $code Application-specific code (default: 'SUCCESS')
     * @return JsonResponse
     */
    protected function success(
        $data = null,
        ?string $message = null,
        int $httpStatus = 200,
        string $code = 'SUCCESS'
    ): JsonResponse {
        $result = [];

        // Handle pagination
        if ($data instanceof LengthAwarePaginator) {
            $result['data'] = $data->items();
            $result['meta'] = [
                'page' => $data->currentPage(),
                'limit' => $data->perPage(),
                'total' => $data->total(),
                'last_page' => $data->lastPage(),
            ];
        } else {
            $result['data'] = $data;
        }

        // Add message if provided
        if ($message) {
            $result['message'] = $message;
        }

        // Add debug message in non-production
        if (config('app.debug')) {
            $result['debug_message'] = null;
        }

        $response = [
            'code' => $code,
            'result' => $result,
        ];

        return response()->json($response, $httpStatus);
    }

    /**
     * Return a success response with pagination
     *
     * @param LengthAwarePaginator $paginator
     * @param string|null $message
     * @param string $code
     * @return JsonResponse
     */
    protected function successWithPagination(
        LengthAwarePaginator $paginator,
        ?string $message = null,
        string $code = 'SUCCESS'
    ): JsonResponse {
        return $this->success($paginator, $message, 200, $code);
    }

    /**
     * Return an error JSON response
     *
     * @param string $errorMessage User-facing error message
     * @param int $httpStatus HTTP status code
     * @param string $code Application-specific error code
     * @param string|null $debugMessage Debug message (only in debug mode)
     * @param mixed $errors Validation errors or additional error details
     * @return JsonResponse
     */
    protected function error(
        string $errorMessage,
        int $httpStatus = 400,
        string $code = 'ERROR',
        ?string $debugMessage = null,
        $errors = null
    ): JsonResponse {
        $result = [
            'data' => null,
            'error_message' => $errorMessage,
        ];

        // Add debug message only in debug mode
        if (config('app.debug') && $debugMessage) {
            $result['debug_message'] = $debugMessage;
        }

        // Add validation errors if provided
        if ($errors) {
            $result['errors'] = $errors;
        }

        $response = [
            'code' => $code,
            'result' => $result,
        ];

        return response()->json($response, $httpStatus);
    }

    /**
     * Return a validation error response
     *
     * @param array $errors Validation errors
     * @param string $message Error message
     * @return JsonResponse
     */
    protected function validationError(array $errors, string $message = 'Validation failed'): JsonResponse
    {
        return $this->error(
            $message,
            422,
            'VALIDATION_ERROR',
            null,
            $errors
        );
    }

    /**
     * Return an unauthorized error response
     *
     * @param string $message Error message
     * @return JsonResponse
     */
    protected function unauthorized(string $message = 'Unauthorized'): JsonResponse
    {
        return $this->error($message, 401, 'UNAUTHORIZED');
    }

    /**
     * Return a not found error response
     *
     * @param string $message Error message
     * @return JsonResponse
     */
    protected function notFound(string $message = 'Resource not found'): JsonResponse
    {
        return $this->error($message, 404, 'NOT_FOUND');
    }
}

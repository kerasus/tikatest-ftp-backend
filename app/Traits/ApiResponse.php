<?php

namespace App\Traits;

use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Contracts\Validation\Validator;

trait ApiResponse
{
    /**
     * Return a success JSON response.
     *
     * @param mixed $data The data to be returned.
     * @param int $statusCode The HTTP status code.
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respondWithSuccess($data = null, int $statusCode = Response::HTTP_OK): JsonResponse
    {
        return response()->json($data, $statusCode);
    }

    /**
     * Return an error JSON response.
     *
     * @param string $message The main error message.
     * @param array|string|null $errors Detailed error messages (array or string).
     * @param int $statusCode The HTTP status code.
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respondWithError(string $message, $errors = null, int $statusCode = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        // Normalize errors to always be an array
        if (is_string($errors)) {
            $errorsArray = [$errors];
        } elseif (is_array($errors)) {
            $errorsArray = $errors;
        } else {
            $errorsArray = [];
        }

        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errorsArray,
        ], $statusCode);
    }

    /**
     * Return a validation error JSON response.
     *
     * @param \Illuminate\Contracts\Validation\Validator $validator The validator instance.
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respondWithValidationErrors(Validator $validator): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'The given data was invalid.',
            'errors' => $validator->errors()->toArray(),
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Return a "Not Found" (404) JSON response.
     *
     * @param string $message The error message.
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respondNotFound(string $message = 'Resource not found'): JsonResponse
    {
        return $this->respondWithError($message, null, Response::HTTP_NOT_FOUND);
    }

    /**
     * Return a "Forbidden" (403) JSON response.
     *
     * @param string $message The error message.
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respondForbidden(string $message = 'Access denied'): JsonResponse
    {
        return $this->respondWithError($message, null, Response::HTTP_FORBIDDEN);
    }

    /**
     * Return an "Unauthorized" (401) JSON response.
     *
     * @param string $message The error message.
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respondUnauthorized(string $message = 'Unauthorized'): JsonResponse
    {
        return $this->respondWithError($message, null, Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Throw a validation exception with a custom JSON response.
     *
     * Use this method inside the 'failedValidation' method of Form Requests
     * or manually within controllers.
     *
     * @param \Illuminate\Contracts\Validation\Validator $validator
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function throwValidationException(Validator $validator)
    {
        throw new ValidationException($validator, $this->respondWithValidationErrors($validator));
    }
}

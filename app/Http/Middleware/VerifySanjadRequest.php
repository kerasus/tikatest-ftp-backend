<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifySanjadRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedKey = config('services.sanjad.inbound_key');
        $providedKey = $request->header('X-Service-Api-Key');

        if (!$expectedKey || !$providedKey || !hash_equals($expectedKey, $providedKey)) {
            return response()->json([
                'success' => false,
                'message' => 'دسترسی غیرمجاز: کلید سرویس سنجاد نامعتبر است.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}

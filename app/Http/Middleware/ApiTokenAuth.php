<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiTokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-API-Token')
               ?? $request->bearerToken();

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'API token tidak ditemukan. Sertakan header X-API-Token.',
            ], 401);
        }

        $user = User::where('api_token', $token)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'API token tidak valid.',
            ], 403);
        }

        auth()->setUser($user);

        return $next($request);
    }
}

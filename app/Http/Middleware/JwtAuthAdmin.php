<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Redis;
use Tymon\JWTAuth\Facades\JWTAuth;

class JwtAuthAdmin
{
    public function handle($request, Closure $next)
    {
        try {
            $token = $request->bearerToken();
            if (!$token) {
                return response()->json(['message' => 'Token tidak ditemukan.'], 401);
            }

            $payload = JWTAuth::setToken($token)->getPayload();
            $username = $payload->get('username');
            if (!$username) {
                return response()->json(['message' => 'Token tidak valid.'], 401);
            }

            $redisKey = "admin:token:$username";
            if (!Redis::exists($redisKey)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token sudah tidak valid. Silakan login ulang.',
                ], 401);
            }
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Token tidak valid.',
                'error' => $e->getMessage(),
            ], 401);
        }

        return $next($request);
    }
}
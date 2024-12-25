<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Support\Facades\Redis;
use Tymon\JWTAuth\Facades\JWTAuth;

class Authenticate
{
    /**
     * The authentication guard factory instance.
     *
     * @var \Illuminate\Contracts\Auth\Factory
     */
    protected $auth;

    /**
     * Create a new middleware instance.
     *
     * @param  \Illuminate\Contracts\Auth\Factory  $auth
     * @return void
     */
    public function __construct(Auth $auth)
    {
        $this->auth = $auth;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string|null  $guard
     * @return mixed
     */
    public function handle($request, Closure $next, $guard = null)
    {
        if ($this->auth->guard($guard)->guest()) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

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

            // Tentukan Redis Key berdasarkan guard
            $redisKey = match ($guard) {
                'admin' => "admin:token:$username",
                'employer' => "mitra:token:$username",
                default => "user:token:$username",
            };

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

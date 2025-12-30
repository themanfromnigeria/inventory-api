<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class EnsureCompanyOwner
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || $user->role !== 'owner') {
            Log::warning('Non-owner user attempting to access owner resource', [
                'user_id' => $user?->id,
                'role' => $user?->role
            ]);

            return response()->json([
                'message' => 'Access denied. Company owner privileges required.'
            ], 403);
        }

        return $next($request);
    }
}

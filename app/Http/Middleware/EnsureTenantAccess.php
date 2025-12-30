<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class EnsureTenantAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            Log::warning('Unauthenticated user attempting to access tenant resource');
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if (!$user->hasCompanyAccess()) {
            Log::warning('User denied access due to inactive status', [
                'user_id' => $user->id,
                'company_id' => $user->company_id,
                'user_active' => $user->active,
                'company_active' => $user->company->active
            ]);

            return response()->json([
                'message' => 'Access denied. Account or company is inactive.'
            ], 403);
        }

        // Add company context to request for easy access in controllers
        $request->merge(['tenant_company_id' => $user->company_id]);

        Log::info('Tenant access granted', [
            'user_id' => $user->id,
            'company_id' => $user->company_id
        ]);

        return $next($request);
    }
}

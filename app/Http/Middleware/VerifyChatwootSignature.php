<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyChatwootSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('chatwoot.verify_signature')) {
            return $next($request);
        }

        $signature = $request->header('X-Chatwoot-Signature');
        $timestamp = $request->header('X-Chatwoot-Timestamp');

        if (! $signature || ! $timestamp) {
            return response('Unauthorized', 401);
        }

        $secret = config('chatwoot.webhook_secret');
        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            return response('Unauthorized', 401);
        }

        if (abs(time() - (int) $timestamp) > 300) {
            return response('Unauthorized', 401);
        }

        return $next($request);
    }
}

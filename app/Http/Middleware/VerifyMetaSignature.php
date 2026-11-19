<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyMetaSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-Hub-Signature-256');

        if (! $signature || ! str_starts_with($signature, 'sha256=')) {
            return response('', 432);
        }

        $secret = config('whatsapp.meta_app_secret');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            return response('', 432);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

// Required on money-moving routes. Same key + same body replays the cached
// response; same key + different body is a 409; a concurrent duplicate while
// the first request is still in flight is also a 409.
class EnsureIdempotent
{
    private const TTL_SECONDS = 86400;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! $key) {
            return response()->json(['message' => 'Idempotency-Key header is required.'], 400);
        }

        $userId = $request->user()?->id ?? 'anon';
        $redisKey = "idem:{$userId}:{$key}";
        $requestHash = hash('sha256', $request->getContent());

        $existing = Redis::get($redisKey);

        if ($existing) {
            $state = json_decode($existing, true);

            if ($state['request_hash'] !== $requestHash) {
                return response()->json([
                    'message' => 'Idempotency-Key was already used with a different request payload.',
                ], 409);
            }

            if ($state['status'] === 'processing') {
                return response()->json(['message' => 'This request is already being processed.'], 409);
            }

            return response()->json($state['body'], $state['status_code'])
                ->header('X-Idempotent-Replay', 'true');
        }

        $acquired = Redis::set($redisKey, json_encode([
            'status' => 'processing',
            'request_hash' => $requestHash,
        ]), 'EX', self::TTL_SECONDS, 'NX');

        if (! $acquired) {
            return response()->json(['message' => 'This request is already being processed.'], 409);
        }

        $response = $next($request);

        Redis::set($redisKey, json_encode([
            'status' => 'completed',
            'request_hash' => $requestHash,
            'status_code' => $response->getStatusCode(),
            'body' => json_decode($response->getContent(), true),
        ]), 'EX', self::TTL_SECONDS);

        return $response;
    }
}

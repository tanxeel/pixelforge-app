<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the Pixelforge API. Throws PixelforgeException with a retryable flag and Retry-After.
 */
class PixelforgeClient
{
    private function http(): PendingRequest
    {
        return Http::baseUrl(config('pixelforge.base_url'))
            ->withToken((string) config('pixelforge.api_key'))
            ->acceptJson()
            ->timeout(15);
    }

    public function pricing(): array
    {
        $res = Http::baseUrl(config('pixelforge.base_url'))->acceptJson()->timeout(10)->get('/pricing');

        return $this->json($res);
    }

    /** Create a generation. Safe to retry with the same $idempotencyKey. */
    public function create(array $params, string $idempotencyKey): array
    {
        try {
            $res = $this->http()->withHeaders(['Idempotency-Key' => $idempotencyKey])->post('/generations', $params);
        } catch (ConnectionException $e) {
            throw new PixelforgeException('Could not reach Pixelforge.', 0, 'connection_error', true);
        }

        return $this->json($res);
    }

    public function find(string $id): array
    {
        try {
            return $this->json($this->http()->get('/generations/'.$id));
        } catch (ConnectionException $e) {
            throw new PixelforgeException('Could not reach Pixelforge.', 0, 'connection_error', true);
        }
    }

    public function usage(string $start, string $end): array
    {
        try {
            return $this->json($this->http()->get('/usage', ['start_date' => $start, 'end_date' => $end]));
        } catch (ConnectionException $e) {
            throw new PixelforgeException('Could not reach Pixelforge.', 0, 'connection_error', true);
        }
    }

    private function json(Response $res): array
    {
        if ($res->successful()) {
            return $res->json() ?? [];
        }

        $err = $res->json('error') ?? [];
        $code = $err['code'] ?? null;
        $retryAfter = $res->header('Retry-After') !== '' ? (int) $res->header('Retry-After') : null;
        // 503 overloaded and other 5xx are transient. 429s are retryable after waiting. Other 4xx are our fault.
        $retryable = $res->serverError() || $res->status() === 429;

        throw new PixelforgeException($err['message'] ?? 'Pixelforge request failed.', $res->status(), $code, $retryable, $retryAfter);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\GenerationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, GenerationService $service): Response
    {
        // Verify against the raw body, before any JSON decoding.
        $payload = $request->getContent();
        $parts = [];
        foreach (explode(',', (string) $request->header('Pixelforge-Signature')) as $part) {
            [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
            $parts[$k] = $v;
        }
        $timestamp = (int) ($parts['t'] ?? 0);
        $secret = (string) config('pixelforge.webhook_secret');
        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        if ($secret === '' || ! hash_equals($expected, $parts['v1'] ?? '')
            || abs(time() - $timestamp) > config('pixelforge.webhook_tolerance')) {
            abort(400, 'Invalid Pixelforge signature.');
        }

        $event = json_decode($payload, true);
        if (! is_array($event) || empty($event['id']) || empty($event['type'])) {
            abort(400, 'Malformed event.');
        }

        $object = $event['data']['object'] ?? [];

        try {
            // Recording the event and applying it happen together: if applying fails we answer 500,
            // the insert rolls back, and Pixelforge's retry is processed normally.
            DB::transaction(function () use ($event, $object, $service) {
                WebhookEvent::create([
                    'event_id' => $event['id'],
                    'type' => $event['type'],
                    'pf_generation_id' => $object['id'] ?? null,
                    'received_at' => now(),
                ]);

                if (($event['type'] ?? '') === 'ping') {
                    return;
                }

                $generation = $this->findGeneration($object);
                if ($generation) {
                    $service->applyRemote($generation, $object);
                }
            });
        } catch (UniqueConstraintViolationException) {
            // The same event delivered again (retry or duplicate delivery). Already handled, acknowledge it.
            WebhookEvent::where('event_id', $event['id'])->increment('attempts');
        }

        return response()->noContent();
    }

    /** The webhook can beat our own POST response, so also match on the metadata we sent. */
    private function findGeneration(array $object): ?Generation
    {
        $uuid = $object['metadata']['generation_uuid'] ?? null;

        if ($uuid && ($g = Generation::where('uuid', $uuid)->first())) {
            return $g;
        }

        return isset($object['id']) ? Generation::where('pf_id', $object['id'])->first() : null;
    }
}

<?php

namespace App\Services;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GenerationService
{
    private const RANK = ['queued' => 0, 'processing' => 1, 'succeeded' => 2, 'failed' => 2];

    public function __construct(private PixelforgeClient $client, private Pricing $pricing) {}

    /**
     * Validate funds, take a hold for the worst-case price, create the row, then submit to Pixelforge.
     * The generation uuid is the Idempotency-Key, so retrying the POST can never create (or bill) a second generation.
     *
     * @throws InsufficientCredits
     */
    public function create(User $user, array $input, array $pricing): Generation
    {
        $images = array_values($input['reference_images'] ?? []);
        $est = $this->pricing->userEstimate($pricing, $input['prompt'], $input['size'], $input['quality'], count($images));

        $generation = DB::transaction(function () use ($user, $input, $images, $est) {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if ($locked->balance_nano < $est['max']) {
                throw new InsufficientCredits($est['max'], $locked->balance_nano);
            }

            $locked->decrement('balance_nano', $est['max']);

            $g = Generation::create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $locked->id,
                'prompt' => $input['prompt'],
                'size' => $input['size'],
                'quality' => $input['quality'],
                'reference_images' => $images,
                'status' => 'queued',
                'estimate_nano' => $est['expected'],
                'reserved_nano' => $est['max'],
            ]);

            CreditTransaction::create(['user_id' => $locked->id, 'generation_id' => $g->id, 'type' => 'hold', 'amount_nano' => -$est['max']]);

            return $g;
        });

        $this->submit($generation);

        return $generation->fresh();
    }

    /** POST to Pixelforge with bounded retries. On a permanent failure the generation fails and the hold is released. */
    public function submit(Generation $g): void
    {
        $params = array_filter([
            'prompt' => $g->prompt,
            'size' => $g->size,
            'quality' => $g->quality,
            'reference_images' => $g->reference_images ?: null,
            // Lets us match webhooks that arrive before this request has even returned.
            'metadata' => ['generation_uuid' => $g->uuid],
        ], fn ($v) => $v !== null);

        $attempts = 0;
        while (true) {
            $attempts++;
            try {
                $remote = $this->client->create($params, $g->uuid);
                $this->applyRemote($g, $remote);

                return;
            } catch (PixelforgeException $e) {
                $concurrency = $e->errorCode === 'concurrency_limit_exceeded';
                if ($e->retryable && $attempts < 4 && ! ($e->errorCode === 'daily_limit_exceeded')) {
                    // Respect Retry-After, back off further each time, and cap the wait so the request stays snappy.
                    $wait = min(max($e->retryAfter ?? 0, $attempts * 2), 6);
                    sleep($wait);

                    continue;
                }

                $message = match (true) {
                    $e->errorCode === 'daily_limit_exceeded' => 'The daily generation limit has been reached. Try again tomorrow (UTC).',
                    $concurrency => 'Too many generations are running right now. Please try again in a few seconds.',
                    $e->errorCode === 'overloaded' => 'The image provider is overloaded. Please try again.',
                    $e->errorCode === 'content_policy_violation' => 'That prompt was rejected by the provider.',
                    default => $e->getMessage(),
                };
                $this->fail($g, $e->errorCode ?? 'submit_failed', $message);

                return;
            }
        }
    }

    /**
     * The single place where provider state becomes our state. Used by webhooks, the create response and polling.
     * It is idempotent: replays, duplicates and out of order events can never double charge or move a final state.
     */
    public function applyRemote(Generation $g, array $remote): void
    {
        DB::transaction(function () use ($g, $remote) {
            $row = Generation::query()->whereKey($g->id)->lockForUpdate()->first();

            if ($row->pf_id === null && isset($remote['id'])) {
                $row->pf_id = $remote['id'];
            }

            $new = $remote['status'] ?? null;
            if ($row->isFinal() || ! isset(self::RANK[$new]) || self::RANK[$new] <= self::RANK[$row->status]) {
                // Already final, or a stale/duplicate event (e.g. a retried "processing" after "succeeded").
                $row->save();
                $g->setRawAttributes($row->getAttributes(), true);

                return;
            }

            if ($new === 'succeeded') {
                $this->settleSuccess($row, $remote);
            } elseif ($new === 'failed') {
                $row->error_code = $remote['error']['code'] ?? 'generation_failed';
                $row->error_message = $remote['error']['message'] ?? 'Generation failed.';
                $this->finish($row, 'failed', $remote);
                $this->release($row, $row->reserved_nano);   // failed generations are never billed
            } else {
                $row->status = $new;
                $row->save();
            }

            $g->setRawAttributes($row->fresh()->getAttributes(), true);
        });
    }

    private function settleSuccess(Generation $row, array $remote): void
    {
        $rates = $remote['rates'] ?? $row->rates;
        $usage = $remote['usage'];
        $provider = Pricing::actualProviderCost($usage, $rates);
        $charged = Money::withMarkup($provider);

        $row->rates = $rates;
        $row->usage = $usage;
        $row->provider_cost_nano = $provider;
        $row->charged_nano = $charged;
        $row->image_url = $remote['image_url'] ?? null;
        $row->image_url_expires_at = isset($remote['image_url_expires_at']) ? Carbon::parse($remote['image_url_expires_at']) : null;
        $this->finish($row, 'succeeded', $remote);

        // The hold covered the worst case; give back what was not used.
        $this->release($row, $row->reserved_nano - $charged);
    }

    private function finish(Generation $row, string $status, array $remote): void
    {
        $row->status = $status;
        $row->completed_at = isset($remote['completed_at']) ? Carbon::parse($remote['completed_at']) : now();
        $row->settled_at = now();
        $row->save();
    }

    private function release(Generation $row, int $amount): void
    {
        if ($amount === 0) {
            return;
        }
        User::query()->whereKey($row->user_id)->increment('balance_nano', $amount);
        CreditTransaction::create(['user_id' => $row->user_id, 'generation_id' => $row->id, 'type' => 'release', 'amount_nano' => $amount]);
    }

    /** Fail locally (e.g. never accepted by Pixelforge) and return the whole hold. */
    public function fail(Generation $g, string $code, string $message): void
    {
        DB::transaction(function () use ($g, $code, $message) {
            $row = Generation::query()->whereKey($g->id)->lockForUpdate()->first();
            if ($row->isFinal()) {
                return;
            }
            $row->error_code = $code;
            $row->error_message = $message;
            $this->finish($row, 'failed', []);
            $this->release($row, $row->reserved_nano);
        });
        $g->refresh();
    }

    /** Fallback for lost webhooks: ask Pixelforge directly about generations that look stuck. */
    public function reconcileStale(User $user, int $olderThanSeconds = 45, int $limit = 5): void
    {
        Generation::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['queued', 'processing'])
            ->where('created_at', '<', now()->subSeconds($olderThanSeconds))
            ->orderBy('created_at')
            ->limit($limit)
            ->get()
            ->each(function (Generation $g) {
                try {
                    if ($g->pf_id) {
                        $this->applyRemote($g, $this->client->find($g->pf_id));
                    } elseif ($g->created_at->lt(now()->subMinutes(2))) {
                        // Never got a response with an id. Re-sending is safe: same Idempotency-Key returns the same generation.
                        $this->submit($g);
                    }
                } catch (PixelforgeException) {
                    // Try again on the next page load.
                }
            });
    }
}

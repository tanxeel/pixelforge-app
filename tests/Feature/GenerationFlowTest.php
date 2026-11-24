<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use App\Services\GenerationService;
use App\Services\Pricing;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GenerationFlowTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'whsec_test';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'pixelforge.base_url' => 'https://pf.test/v1',
            'pixelforge.api_key' => 'pk_test',
            'pixelforge.webhook_secret' => $this->secret,
        ]);
    }

    private function pricingJson(): array
    {
        return [
            'object' => 'pricing', 'currency' => 'usd',
            'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
            'tokens' => [
                'characters_per_text_token' => 4, 'text_token_variance' => 0.15, 'per_reference_image' => 765,
                'output' => ['low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420], 'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650], 'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450]],
                'reasoning' => ['low' => ['min' => 0, 'max' => 500], 'medium' => ['min' => 200, 'max' => 2000], 'high' => ['min' => 1000, 'max' => 8000]],
            ],
        ];
    }

    private function remote(Generation $g, string $status, array $extra = []): array
    {
        return array_merge([
            'id' => 'gen_ABC', 'object' => 'generation', 'status' => $status, 'prompt' => $g->prompt,
            'metadata' => ['generation_uuid' => $g->uuid],
            'rates' => $this->pricingJson()['rates'], 'image_url' => null, 'image_url_expires_at' => null,
            'usage' => null, 'error' => null, 'completed_at' => null,
        ], $extra);
    }

    private function succeeded(Generation $g): array
    {
        return $this->remote($g, 'succeeded', [
            'usage' => ['input_text_tokens' => 12, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 500, 'total_tokens' => 1612],
            'image_url' => 'https://pf.test/images/gen_ABC.png', 'image_url_expires_at' => now()->addDay()->toIso8601String(),
            'completed_at' => now()->toIso8601String(),
        ]);
    }

    private function makeGeneration(User $user, int $balanceDollars = 5): Generation
    {
        $user->forceFill(['balance_nano' => $balanceDollars * 1_000_000_000])->save();
        Http::fake([
            'pf.test/v1/pricing' => Http::response($this->pricingJson()),
            'pf.test/v1/generations' => Http::response(['id' => 'gen_ABC', 'object' => 'generation', 'status' => 'queued', 'metadata' => ['generation_uuid' => 'x']], 202),
        ]);
        $service = app(GenerationService::class);

        return $service->create($user, ['prompt' => 'a red fox', 'size' => '1024x1024', 'quality' => 'medium'], app(Pricing::class)->get());
    }

    private function signedWebhook(array $event, ?int $ts = null, ?string $secret = null)
    {
        $body = json_encode($event);
        $ts ??= time();
        $sig = hash_hmac('sha256', $ts.'.'.$body, $secret ?? $this->secret);

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'HTTP_Pixelforge-Signature' => "t=$ts,v1=$sig", 'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    public function test_create_takes_a_hold_and_success_charges_cost_plus_25_percent_exactly_once(): void
    {
        $user = User::factory()->create();
        $g = $this->makeGeneration($user);
        $this->assertSame('gen_ABC', $g->pf_id);
        $held = $user->fresh()->balance_nano;
        $this->assertLessThan(5_000_000_000, $held);              // a hold was taken

        $event = ['id' => 'evt_1', 'type' => 'generation.succeeded', 'data' => ['object' => $this->succeeded($g)]];
        $this->signedWebhook($event)->assertNoContent();
        $this->signedWebhook($event)->assertNoContent();            // duplicate delivery

        $g->refresh();
        // 12*5 + 1100*40 + 500*12.5 = 60 + 44000 + 6250 = 50310 micro-dollars = 0.050310000
        $this->assertSame('0.050310000', Money::decimal($g->provider_cost_nano));
        $this->assertSame('0.062887500', Money::decimal($g->charged_nano));
        $this->assertSame(5_000_000_000 - $g->charged_nano, $user->fresh()->balance_nano);
        $this->assertSame(1, \App\Models\WebhookEvent::count());
        $this->assertSame(1, \App\Models\CreditTransaction::where('type', 'release')->count());
    }

    public function test_a_late_processing_event_never_moves_a_succeeded_generation_back(): void
    {
        $user = User::factory()->create();
        $g = $this->makeGeneration($user);

        $this->signedWebhook(['id' => 'evt_2', 'type' => 'generation.succeeded', 'data' => ['object' => $this->succeeded($g)]])->assertNoContent();
        $this->signedWebhook(['id' => 'evt_3', 'type' => 'generation.processing', 'data' => ['object' => $this->remote($g, 'processing')]])->assertNoContent();

        $this->assertSame('succeeded', $g->fresh()->status);
    }

    public function test_failure_releases_the_whole_hold(): void
    {
        $user = User::factory()->create();
        $g = $this->makeGeneration($user);

        $failed = $this->remote($g, 'failed', ['error' => ['code' => 'internal_error', 'message' => 'oops'], 'completed_at' => now()->toIso8601String()]);
        $this->signedWebhook(['id' => 'evt_4', 'type' => 'generation.failed', 'data' => ['object' => $failed]])->assertNoContent();

        $this->assertSame('failed', $g->fresh()->status);
        $this->assertSame(5_000_000_000, $user->fresh()->balance_nano);
        $this->assertNull($g->fresh()->charged_nano);
    }

    public function test_webhook_that_arrives_before_the_create_response_is_matched_by_metadata(): void
    {
        $user = User::factory()->create(['balance_nano' => 0]);
        $g = Generation::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $user->id, 'prompt' => 'x', 'size' => '1024x1024',
            'quality' => 'medium', 'status' => 'queued', 'estimate_nano' => 1, 'reserved_nano' => 0,
        ]);

        $this->signedWebhook(['id' => 'evt_5', 'type' => 'generation.processing', 'data' => ['object' => $this->remote($g, 'processing')]])->assertNoContent();

        $g->refresh();
        $this->assertSame('processing', $g->status);
        $this->assertSame('gen_ABC', $g->pf_id);
    }

    public function test_bad_signature_and_old_timestamps_are_rejected(): void
    {
        $event = ['id' => 'evt_6', 'type' => 'ping', 'data' => ['object' => []]];

        $this->signedWebhook($event, null, 'wrong')->assertStatus(400);
        $this->signedWebhook($event, time() - 3600)->assertStatus(400);
        $this->assertSame(0, \App\Models\WebhookEvent::count());
    }

    public function test_insufficient_credits_blocks_generation_and_nothing_is_sent(): void
    {
        $user = User::factory()->create(['balance_nano' => 1000]);
        Http::fake(['pf.test/v1/pricing' => Http::response($this->pricingJson())]);

        $this->actingAs($user)->post('/generate', ['prompt' => 'a red fox', 'size' => '1024x1024', 'quality' => 'high'])
            ->assertSessionHasErrors('prompt');

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/generations'));
        $this->assertSame(0, Generation::count());
    }

    public function test_provider_rejection_fails_the_generation_and_refunds_the_hold(): void
    {
        $user = User::factory()->create(['balance_nano' => 5_000_000_000]);
        Http::fake([
            'pf.test/v1/pricing' => Http::response($this->pricingJson()),
            'pf.test/v1/generations' => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'no']], 400),
        ]);

        $g = app(GenerationService::class)->create($user, ['prompt' => '[blocked]', 'size' => '1024x1024', 'quality' => 'low'], app(Pricing::class)->get());

        $this->assertSame('failed', $g->status);
        $this->assertSame(5_000_000_000, $user->fresh()->balance_nano);
    }

    public function test_per_user_daily_limit_protects_the_shared_provider_quota(): void
    {
        config(['pixelforge.limits.per_user_daily' => 1]);
        $user = User::factory()->create();
        $this->makeGeneration($user);
        $this->signedWebhook(['id' => 'evt_9', 'type' => 'generation.succeeded', 'data' => ['object' => $this->succeeded(Generation::first())]]);

        $this->actingAs($user)->post('/generate', ['prompt' => 'another', 'size' => '1024x1024', 'quality' => 'low'])
            ->assertSessionHasErrors('prompt');

        $this->assertSame(1, Generation::count());
    }
}

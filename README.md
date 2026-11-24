# Pixelgen: Pixelforge take-home

Laravel 13, Vue 3, Inertia. Users sign in, generate images through the Pixelforge API, see a cost estimate before submitting, and are charged the provider's real cost plus 25%.

## Run locally

```bash
composer install && npm ci && npm run build
cp .env.example .env && php artisan key:generate
# set PIXELFORGE_API_KEY and PIXELFORGE_WEBHOOK_SECRET (and optionally ADMIN_EMAIL) in .env
php artisan migrate
php artisan serve
```

Webhook endpoint: `POST /webhooks/pixelforge` (point the API key's webhook URL at it; use a tunnel locally).
Tests: `php artisan test`.

## Decisions

**Money is integer nanodollars.** Pixelforge costs are exact decimals with nine places, so every amount is stored as a `BIGINT` nanodollar count and computed with `bcmath`. No floats anywhere. The 25% markup is `ceil(cost * 1.25)` to a whole nanodollar, so we never under-charge by rounding.

**Rates are read from `GET /v1/pricing`** (cached 5 minutes) and never hardcoded. Each generation stores the `rates` Pixelforge billed it at, and the final cost is computed from the usage and rates in the completion event.

**Estimate vs actual.** Before submitting we show an expected price plus a range. Text tokens are `ceil(chars/4)` with the documented 15% variance, reference images are 765 tokens each, output tokens are exact for the chosen size and quality, and reasoning is a range by quality (expected value is the midpoint). The real cost is recorded when the generation succeeds.

**Credit holds.** On submit we reserve the worst-case price inside a transaction (row lock on the user), so a user can never go negative even with several generations in flight. On success we release `reserved - actual`. On failure we release everything, since failed generations are never billed. Funds are checked before anything is sent to Pixelforge.

**One state machine for every source of truth.** The webhook, the create response and the polling fallback all call `GenerationService::applyRemote()`. It locks the row, ignores anything that would move a generation backwards or out of a final state, and settles exactly once (`settled_at`). That gives the required behaviour: a late `processing` after `succeeded` is ignored, and charging happens once even if events are duplicated.

**Webhooks.** Signature is verified over the raw body with HMAC-SHA256 in constant time and a 5 minute timestamp window. The event id is stored with a unique index in the same transaction that applies the event: duplicates are acknowledged without being processed twice, and if applying fails the insert rolls back and Pixelforge's retry is processed normally. A webhook can beat our own POST response, so each request carries `metadata.generation_uuid` and events are matched on it before falling back to `pf_id`. The route sits outside CSRF.

**Retries and idempotency.** Each generation's uuid is sent as the `Idempotency-Key`, so retrying a create (503 overloaded, 429, network errors) can never create or bill a second generation. Retries honour `Retry-After`, back off, and give up after a few attempts, then fail the generation and refund the hold.

**Lost webhooks.** Delivery is not guaranteed, so loading the history page reconciles generations that look stuck by polling `GET /v1/generations/{id}`. A generation that never received an id is re-submitted with the same idempotency key.

**Webhook work is inline.** Applying an event is a couple of indexed writes, so it runs in the request and answers well inside Pixelforge's 10 second limit. With heavier work I would queue a job after recording the event.

**Admin report** (`ADMIN_EMAIL`): credits spent, provider cost and profit per UTC day and per user, grouped on the day a generation completed (the same basis Pixelforge bills on), with a live comparison to `GET /v1/usage`.

## Trade-offs

- Images are linked, not stored (URLs expire after 24 hours; the UI shows "Image expired").
- "Buy credits" is a fake purchase that adds $5, $10 or $25.
- No email verification, to keep the review path short.

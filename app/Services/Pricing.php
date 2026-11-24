<?php

namespace App\Services;

use App\Support\Money;
use Illuminate\Support\Facades\Cache;

/**
 * Cost estimation from Pixelforge's published pricing. Rates are never hardcoded: they come from GET /v1/pricing
 * (cached briefly) and each generation keeps the rates it was created with.
 */
class Pricing
{
    public function __construct(private PixelforgeClient $client) {}

    public function get(): array
    {
        $fresh = Cache::remember('pixelforge.pricing', 300, fn () => $this->client->pricing());
        // Keep a long-lived copy so a short outage of the pricing endpoint doesn't break the page.
        Cache::put('pixelforge.pricing.stale', $fresh, 86400);

        return $fresh;
    }

    public function safeGet(): ?array
    {
        try {
            return $this->get();
        } catch (\Throwable) {
            return Cache::get('pixelforge.pricing.stale');
        }
    }

    /**
     * Estimate in nanodollars of what Pixelforge will bill us. text tokens are an estimate (+-15%), reasoning is a range.
     *
     * @return array{min:int,expected:int,max:int} provider cost
     */
    public function providerEstimate(array $pricing, string $prompt, string $size, string $quality, int $imageCount): array
    {
        $rates = $pricing['rates'];
        $t = $pricing['tokens'];

        $textEst = (int) ceil(mb_strlen($prompt) / $t['characters_per_text_token']);
        $variance = $t['text_token_variance'];                        // 0.15
        $textMin = (int) floor($textEst * (1 - $variance));
        $textMax = (int) ceil($textEst * (1 + $variance));
        $images = $imageCount * $t['per_reference_image'];
        $output = $t['output'][$quality][$size];
        $rMin = $t['reasoning'][$quality]['min'];
        $rMax = $t['reasoning'][$quality]['max'];
        $rMid = intdiv($rMin + $rMax, 2);

        $cost = fn (int $text, int $reasoning) => Money::tokenCost($text, $rates['input_text_tokens'])
            + Money::tokenCost($images, $rates['input_image_tokens'])
            + Money::tokenCost($output, $rates['output_tokens'])
            + Money::tokenCost($reasoning, $rates['reasoning_tokens']);

        return [
            'min' => $cost($textMin, $rMin),
            'expected' => $cost($textEst, $rMid),
            'max' => $cost($textMax, $rMax),
        ];
    }

    /** Same estimate as the user sees: provider cost plus the 25% markup. */
    public function userEstimate(array $pricing, string $prompt, string $size, string $quality, int $imageCount): array
    {
        $p = $this->providerEstimate($pricing, $prompt, $size, $quality, $imageCount);

        return array_map(fn (int $n) => Money::withMarkup($n), $p);
    }

    /** Actual provider cost from final usage and the rates the generation was billed at. */
    public static function actualProviderCost(array $usage, array $rates): int
    {
        return Money::tokenCost((int) $usage['input_text_tokens'], $rates['input_text_tokens'])
            + Money::tokenCost((int) $usage['input_image_tokens'], $rates['input_image_tokens'])
            + Money::tokenCost((int) $usage['output_tokens'], $rates['output_tokens'])
            + Money::tokenCost((int) $usage['reasoning_tokens'], $rates['reasoning_tokens']);
    }
}

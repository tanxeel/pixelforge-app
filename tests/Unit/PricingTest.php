<?php

namespace Tests\Unit;

use App\Services\Pricing;
use App\Services\PixelforgeClient;
use App\Support\Money;
use PHPUnit\Framework\TestCase;

class PricingTest extends TestCase
{
    private array $pricing = [
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'tokens' => [
            'characters_per_text_token' => 4, 'text_token_variance' => 0.15, 'per_reference_image' => 765,
            'output' => ['low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420], 'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650], 'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450]],
            'reasoning' => ['low' => ['min' => 0, 'max' => 500], 'medium' => ['min' => 200, 'max' => 2000], 'high' => ['min' => 1000, 'max' => 8000]],
        ],
    ];

    public function test_matches_the_documented_worst_case_example(): void
    {
        // Docs: high 1536x1024, 400 char prompt, 2 reference images, max text and max reasoning = $0.373875000.
        $pricing = new Pricing($this->createStub(PixelforgeClient::class));
        $est = $pricing->providerEstimate($this->pricing, str_repeat('a', 400), '1536x1024', 'high', 2);

        $this->assertSame('0.373875000', Money::decimal($est['max']));
    }

    public function test_markup_is_exact_and_rounds_up(): void
    {
        $this->assertSame(125, Money::withMarkup(100));
        $this->assertSame(2, Money::withMarkup(1));   // 1.25 rounds up to 2 nano
        $this->assertSame('0.467343750', Money::decimal(Money::withMarkup(373875000)));
    }

    public function test_actual_cost_from_usage_matches_docs_example(): void
    {
        // Docs usage example: 12 text, 765 image, 6450 output, 3184 reasoning at the listed rates.
        $usage = ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184];
        $cost = Pricing::actualProviderCost($usage, $this->pricing['rates']);

        // 12*5 + 765*10 + 6450*40 + 3184*12.5 = 60 + 7650 + 258000 + 39800 = 305510 micro-dollars
        $this->assertSame('0.305510000', Money::decimal($cost));
    }
}

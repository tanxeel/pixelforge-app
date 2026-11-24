<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Services\GenerationService;
use App\Services\InsufficientCredits;
use App\Services\LimitReached;
use App\Services\Pricing;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class GenerationController extends Controller
{
    public function create(Request $request, Pricing $pricing)
    {
        return Inertia::render('Generate', [
            'pricing' => $pricing->safeGet(),
            'balance' => Money::decimal($request->user()->balance_nano),
            'sizes' => config('pixelforge.sizes'),
            'qualities' => config('pixelforge.qualities'),
        ]);
    }

    public function store(Request $request, Pricing $pricing, GenerationService $service)
    {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', 'in:'.implode(',', config('pixelforge.sizes'))],
            'quality' => ['required', 'in:'.implode(',', config('pixelforge.qualities'))],
            'reference_images' => ['nullable', 'array', 'max:4'],
            'reference_images.*' => ['required', 'url:http,https', 'max:2000'],
        ]);

        $rates = $pricing->safeGet();
        if (! $rates) {
            throw ValidationException::withMessages(['prompt' => 'Pricing is unavailable right now. Please try again shortly.']);
        }

        try {
            $service->create($request->user(), $data, $rates);
        } catch (LimitReached $e) {
            throw ValidationException::withMessages(['prompt' => $e->getMessage()]);
        } catch (InsufficientCredits $e) {
            throw ValidationException::withMessages([
                'prompt' => 'Not enough credits. This generation needs up to $'.Money::decimal($e->required).' held, you have $'.Money::decimal($e->balance).'.',
            ]);
        }

        return redirect()->route('history');
    }

    public function index(Request $request, GenerationService $service)
    {
        $user = $request->user();
        // Lost webhooks must not leave generations stuck forever.
        $service->reconcileStale($user);

        $generations = Generation::where('user_id', $user->id)->latest('id')->limit(100)->get()
            ->map(fn (Generation $g) => [
                'id' => $g->id,
                'prompt' => $g->prompt,
                'size' => $g->size,
                'quality' => $g->quality,
                'status' => $g->status,
                'estimate' => Money::decimal($g->estimate_nano),
                'actual' => $g->charged_nano === null ? null : Money::decimal($g->charged_nano),
                'image_url' => $g->image_url && $g->image_url_expires_at?->isFuture() ? $g->image_url : null,
                'image_expired' => $g->image_url && ! $g->image_url_expires_at?->isFuture(),
                'error' => $g->error_message,
                'created_at' => $g->created_at->toIso8601String(),
            ]);

        return Inertia::render('History', [
            'generations' => $generations,
            'balance' => Money::decimal($user->fresh()->balance_nano),
        ]);
    }
}

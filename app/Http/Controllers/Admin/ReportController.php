<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Generation;
use App\Services\PixelforgeClient;
use App\Services\PixelforgeException;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReportController extends Controller
{
    public function __invoke(Request $request, PixelforgeClient $client)
    {
        abort_unless($request->user()->isAdmin(), 403);

        // Pixelforge bills on the UTC day a generation completed, so we group on the same basis.
        $rows = Generation::query()
            ->with('user:id,name,email')
            ->where('status', 'succeeded')
            ->whereNotNull('completed_at')
            ->orderBy('completed_at')
            ->get();

        $byDay = [];
        $byDayUser = [];
        foreach ($rows as $g) {
            $day = $g->completed_at->copy()->utc()->toDateString();
            $blank = ['charged' => 0, 'provider' => 0, 'count' => 0, 'day' => $day];

            $byDay[$day] ??= $blank + ['user' => null];
            $byDay[$day]['charged'] += $g->charged_nano;
            $byDay[$day]['provider'] += $g->provider_cost_nano;
            $byDay[$day]['count']++;

            $key = $day.'|'.$g->user_id;
            $byDayUser[$key] ??= $blank + ['user' => $g->user?->email];
            $byDayUser[$key]['charged'] += $g->charged_nano;
            $byDayUser[$key]['provider'] += $g->provider_cost_nano;
            $byDayUser[$key]['count']++;
        }

        $fmt = fn (array $r) => [
            'day' => $r['day'],
            'user' => $r['user'],
            'generations' => $r['count'],
            'credits_spent' => Money::decimal($r['charged']),
            'provider_cost' => Money::decimal($r['provider']),
            'profit' => Money::decimal($r['charged'] - $r['provider']),
        ];

        // Cross-check against Pixelforge's own record of what it billed this key.
        $recon = null;
        $error = null;
        if ($rows->isNotEmpty()) {
            $start = Carbon::parse($rows->first()->completed_at)->utc()->toDateString();
            $end = now()->utc()->toDateString();
            try {
                $usage = $client->usage($start, $end);
                $recon = collect($usage['data'])->map(function ($d) use ($byDay) {
                    $ours = $byDay[$d['date']]['provider'] ?? 0;
                    $theirs = Money::fromDecimal($d['cost_usd']);

                    return [
                        'day' => $d['date'],
                        'ours' => Money::decimal($ours),
                        'pixelforge' => $d['cost_usd'],
                        'matches' => $ours === $theirs,
                    ];
                })->filter(fn ($r) => $r['ours'] !== '0.000000000' || $r['pixelforge'] !== '0.000000000')->values();
            } catch (PixelforgeException $e) {
                $error = 'Could not load Pixelforge usage: '.$e->getMessage();
            }
        }

        $totals = ['charged' => $rows->sum('charged_nano'), 'provider' => $rows->sum('provider_cost_nano')];

        return Inertia::render('Admin/Report', [
            'perDay' => collect($byDay)->sortKeys()->map($fmt)->values(),
            'perDayUser' => collect($byDayUser)->sortKeys()->map($fmt)->values(),
            'totals' => [
                'credits_spent' => Money::decimal($totals['charged']),
                'provider_cost' => Money::decimal($totals['provider']),
                'profit' => Money::decimal($totals['charged'] - $totals['provider']),
            ],
            'reconciliation' => $recon,
            'reconciliationError' => $error,
        ]);
    }
}

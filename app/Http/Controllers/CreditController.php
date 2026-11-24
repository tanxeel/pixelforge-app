<?php

namespace App\Http\Controllers;

use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreditController extends Controller
{
    /** Fake purchase: no payment is taken, it just adds credits. Packs are whole dollars. */
    public function buy(Request $request)
    {
        $data = $request->validate(['dollars' => ['required', 'integer', 'in:5,10,25']]);
        $nano = $data['dollars'] * 1_000_000_000;

        DB::transaction(function () use ($request, $nano) {
            User::query()->whereKey($request->user()->id)->increment('balance_nano', $nano);
            CreditTransaction::create(['user_id' => $request->user()->id, 'type' => 'purchase', 'amount_nano' => $nano]);
        });

        return back();
    }
}

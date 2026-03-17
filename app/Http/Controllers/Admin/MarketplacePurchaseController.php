<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MarketplacePurchase;
use Illuminate\Http\Request;

class MarketplacePurchaseController extends Controller
{
    public function index(Request $request)
    {
        $query = MarketplacePurchase::query()
            ->with([
                'buyer:id,name,email',
                'offer:id,title,user_id,price,currency,is_active',
                'offer.seller:id,name,email',
            ]);

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('currency')) {
            $query->where('currency', strtoupper((string) $request->query('currency')));
        }

        if ($request->filled('buyer_id') && is_numeric($request->query('buyer_id'))) {
            $query->where('buyer_id', (int) $request->query('buyer_id'));
        }

        if ($request->filled('offer_id') && is_numeric($request->query('offer_id'))) {
            $query->where('offer_id', (int) $request->query('offer_id'));
        }

        $query->orderByDesc('id');

        $perPage = (int) $request->query('per_page', 20);
        if ($perPage < 1) $perPage = 1;
        if ($perPage > 100) $perPage = 100;

        return response()->json($query->paginate($perPage));
    }

    public function show(MarketplacePurchase $purchase)
    {
        $purchase->load([
            'buyer:id,name,email',
            'offer',
            'offer.seller:id,name,email',
        ]);

        return response()->json(['purchase' => $purchase], 200);
    }
}


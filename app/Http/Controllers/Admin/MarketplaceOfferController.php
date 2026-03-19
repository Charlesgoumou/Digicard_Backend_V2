<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceOffer;
use App\Models\User;
use Illuminate\Http\Request;

class MarketplaceOfferController extends Controller
{
    public function index(Request $request)
    {
        $query = MarketplaceOffer::query()->with(['seller:id,name,email']);

        if ($request->filled('q')) {
            $q = trim((string) $request->query('q'));
            $query->where(function ($sub) use ($q) {
                $sub->where('title', 'like', '%' . $q . '%')
                    ->orWhere('description', 'like', '%' . $q . '%');
            });
        }

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->filled('currency')) {
            $query->where('currency', strtoupper((string) $request->query('currency')));
        }

        if ($request->filled('is_active')) {
            $isActive = $request->query('is_active') === 'true' || $request->query('is_active') === true || $request->query('is_active') === 1 || $request->query('is_active') === '1';
            $query->where('is_active', $isActive);
        }

        if ($request->filled('seller_id') && is_numeric($request->query('seller_id'))) {
            $query->where('user_id', (int) $request->query('seller_id'));
        }

        $query->orderByDesc('id');

        $perPage = (int) $request->query('per_page', 20);
        if ($perPage < 1) $perPage = 1;
        if ($perPage > 100) $perPage = 100;

        return response()->json($query->paginate($perPage));
    }

    public function show(MarketplaceOffer $offer)
    {
        $offer->load(['seller:id,name,email', 'images', 'reviews', 'purchases', 'messages']);
        return response()->json(['offer' => $offer], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'seller_id' => 'required|integer|exists:users,id',
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'type' => 'required|in:offer,product,service',
            'price' => 'required|numeric|min:0',
            'currency' => 'sometimes|string|size:3',
            'image_url' => 'nullable|string|max:2048',
            'is_active' => 'sometimes|boolean',
        ]);

        $seller = User::findOrFail((int) $validated['seller_id']);

        $offer = MarketplaceOffer::create([
            'user_id' => $seller->id,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'type' => $validated['type'],
            'price' => $validated['price'],
            'currency' => strtoupper($validated['currency'] ?? 'GNF'),
            'image_url' => $validated['image_url'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        $offer->load(['seller:id,name,email']);

        return response()->json(['offer' => $offer], 201);
    }

    public function update(Request $request, MarketplaceOffer $offer)
    {
        $validated = $request->validate([
            'seller_id' => 'sometimes|integer|exists:users,id',
            'title' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string',
            'type' => 'sometimes|required|in:offer,product,service',
            'price' => 'sometimes|required|numeric|min:0',
            'currency' => 'sometimes|string|size:3',
            'image_url' => 'nullable|string|max:2048',
            'is_active' => 'sometimes|boolean',
        ]);

        if (isset($validated['seller_id'])) {
            $offer->user_id = (int) $validated['seller_id'];
        }
        if (array_key_exists('title', $validated)) $offer->title = $validated['title'];
        if (array_key_exists('description', $validated)) $offer->description = $validated['description'];
        if (array_key_exists('type', $validated)) $offer->type = $validated['type'];
        if (array_key_exists('price', $validated)) $offer->price = $validated['price'];
        if (array_key_exists('currency', $validated)) $offer->currency = strtoupper((string) $validated['currency']);
        if (array_key_exists('image_url', $validated)) $offer->image_url = $validated['image_url'];
        if (array_key_exists('is_active', $validated)) $offer->is_active = (bool) $validated['is_active'];

        $offer->save();
        $offer->load(['seller:id,name,email']);

        return response()->json(['offer' => $offer], 200);
    }

    public function destroy(MarketplaceOffer $offer)
    {
        $offer->delete();
        return response()->json(['message' => 'Offre supprimée.'], 200);
    }

    public function toggle(MarketplaceOffer $offer)
    {
        $offer->is_active = !$offer->is_active;
        $offer->save();
        return response()->json(['offer' => $offer], 200);
    }
}


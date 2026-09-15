<?php

namespace App\Http\Controllers;

use App\Jobs\ParseYandexOrganizationJob;
use App\Models\Organization;
use App\Services\YandexMaps\YandexMapsUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrganizationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $organization = Organization::query()
            ->where('user_id', $request->user()->id)
            ->latest('updated_at')
            ->first();

        return response()->json(['organization' => $organization]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
        ]);

        if (! YandexMapsUrl::isYandexMapsUrl($validated['url'])) {
            throw ValidationException::withMessages([
                'url' => 'Нужна ссылка на карточку организации Яндекс.Карт.',
            ]);
        }

        $businessId = YandexMapsUrl::extractBusinessId($validated['url'])
            ?? YandexMapsUrl::pendingBusinessId($validated['url']);

        $existing = Organization::query()->where('user_id', $request->user()->id)
            ->where('yandex_url', $validated['url'])->first();
        if ($existing !== null) {
            $businessId = $existing->yandex_business_id;
        }

        $organization = Organization::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'yandex_business_id' => $businessId],
            [
                'yandex_url' => $validated['url'],
                'status' => 'queued',
                'parse_progress' => 0,
                'last_error' => null,
            ]
        );

        ParseYandexOrganizationJob::dispatch($organization->id);

        return response()->json(['organization' => $organization->refresh()], 202);
    }

    public function reviews(Request $request): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        $organization = Organization::query()
            ->where('user_id', $request->user()->id)
            ->latest('updated_at')
            ->firstOrFail();

        $reviews = $organization->reviews()
            ->latest('reviewed_at')
            ->paginate(50);

        return response()->json([
            'organization' => $organization,
            'reviews' => $reviews,
        ]);
    }
}

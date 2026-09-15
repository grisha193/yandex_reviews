<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\Review;
use App\Services\YandexMaps\YandexMapsParser;
use App\Services\YandexMaps\YandexMapsParserException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ParseYandexOrganizationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 90;

    public function __construct(public int $organizationId)
    {
    }

    public function handle(YandexMapsParser $parser): void
    {
        $organization = Organization::query()->findOrFail($this->organizationId);
        $organization->update(['status' => 'running', 'parse_progress' => 5, 'last_error' => null]);

        try {
            $parsed = $parser->parse($organization->yandex_url, function (int $progress) use ($organization): void {
                $organization->update(['parse_progress' => min(95, max(5, $progress))]);
            });

            DB::transaction(function () use ($organization, $parsed): void {
                $target = Organization::query()
                    ->where('user_id', $organization->user_id)
                    ->where('yandex_business_id', $parsed['business_id'])
                    ->whereKeyNot($organization->id)
                    ->first() ?? $organization;

                $target->update([
                    'yandex_url' => $organization->yandex_url,
                    'name' => $parsed['name'],
                    'yandex_business_id' => $parsed['business_id'],
                    'rating' => $parsed['rating'],
                    'ratings_count' => $parsed['ratings_count'],
                    'reviews_count' => $parsed['reviews_count'],
                    'status' => 'ready',
                    'parse_progress' => 100,
                    'last_error' => null,
                    'parsed_at' => now(),
                ]);

                $target->snapshots()->create([
                    'rating' => $parsed['rating'],
                    'ratings_count' => $parsed['ratings_count'],
                    'reviews_count' => $parsed['reviews_count'],
                    'payload' => $parsed['raw'],
                ]);

                foreach ($parsed['reviews'] as $review) {
                    Review::query()->updateOrCreate(
                        [
                            'organization_id' => $target->id,
                            'external_id' => $review['external_id'],
                        ],
                        $review
                    );
                }

                if (! $target->is($organization)) {
                    $organization->delete();
                }
            });
        } catch (YandexMapsParserException $exception) {
            $organization->update([
                'status' => 'failed',
                'last_error' => $exception->getMessage(),
            ]);

            Log::warning('Yandex Maps parser failed', [
                'organization_id' => $organization->id,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        } catch (Throwable $exception) {
            $organization->update([
                'status' => 'failed',
                'last_error' => 'Внутренняя ошибка парсера: '.$exception->getMessage(),
            ]);

            Log::error('Yandex Maps parser crashed', [
                'organization_id' => $organization->id,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }
}

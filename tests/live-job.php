<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$organization = App\Models\Organization::query()->where('yandex_url', 'https://yandex.ru/maps/-/CTtP72Zr')->latest()->firstOrFail();
$parser = app(App\Services\YandexMaps\YandexMapsParser::class);
(new App\Jobs\ParseYandexOrganizationJob($organization->id))->handle($parser);
$first = $organization->reviews()->count();
(new App\Jobs\ParseYandexOrganizationJob($organization->id))->handle($parser);
$organization->refresh();
if ($first !== 166 || $organization->reviews()->count() !== $first) throw new RuntimeException('Unexpected review count or duplicates');
$pages = [];
for ($page = 1; $page <= 4; $page++) {
    $pages[] = $organization->reviews()->latest('reviewed_at')->paginate(50, ['*'], 'page', $page)->count();
}
echo json_encode(['status' => $organization->status, 'rating' => $organization->rating, 'ratings' => $organization->ratings_count, 'reviews' => $first, 'page_sizes' => $pages, 'snapshots' => $organization->snapshots()->count()], JSON_UNESCAPED_UNICODE)."\n";

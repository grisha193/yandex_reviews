<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\YandexMaps\YandexMapsParser;
use App\Services\YandexMaps\YandexMapsParserException;
use Illuminate\Support\Facades\Http;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function source(bool $missingCount = false, bool $repeat = false): void
{
    Http::swap(new Illuminate\Http\Client\Factory());
    Http::preventStrayRequests();
    Http::fake(function ($request) use ($missingCount, $repeat) {
        if (str_contains($request->url(), '/maps/org/')) {
            $rating = ['ratingValue' => 4.8, 'ratingCount' => 207, 'reviewCount' => 166];
            if ($missingCount) unset($rating['ratingCount']);
            return Http::response('<script type="application/json">'.json_encode([
                'stack' => [['response' => ['items' => [
                    ['id' => '999', 'title' => 'Other', 'ratingData' => ['ratingValue' => 1, 'ratingCount' => 1, 'reviewCount' => 1]],
                    ['id' => '123', 'title' => 'Test', 'ratingData' => $rating],
                ]]]],
            ]).'</script>');
        }
        if ($request->method() === 'POST') return Http::response(['csrfToken' => 'test']);
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
        $page = (int) $query['page'];
        $offset = $repeat ? 0 : ($page - 1) * 50;
        $reviews = [];
        for ($i = $offset; $i < min($offset + 50, 166); $i++) {
            $reviews[] = ['reviewId' => (string) $i, 'author' => ['name' => 'Author'], 'text' => 'Review '.$i, 'rating' => 5, 'updatedTime' => '2026-09-01T12:00:00Z'];
        }
        if ($page === 1 && isset($reviews[0])) {
            unset($reviews[0]['text']);
            $reviews[0]['rating'] = 0;
        }
        return Http::response(['data' => ['reviews' => $reviews, 'params' => ['page' => $page, 'totalPages' => 4, 'count' => 166]]]);
    });
}

source();
$result = (new YandexMapsParser())->parse('https://yandex.ru/maps/org/test/123/');
check(count($result['reviews']) === 166, 'All pages must be loaded');
check($result['rating'] === 4.8 && $result['ratings_count'] === 207 && $result['reviews_count'] === 166, 'Summary must match the organization and preserve distinct counts');
check(count(array_unique(array_column($result['reviews'], 'external_id'))) === 166, 'Review IDs must be unique');
check($result['reviews'][0]['text'] === '' && $result['reviews'][0]['rating'] === 0, 'Empty text and zero rating reviews must be preserved');
foreach ([[true, false], [false, true]] as [$missing, $repeat]) {
    source($missing, $repeat);
    try {
        (new YandexMapsParser())->parse('https://yandex.ru/maps/org/test/123/');
        throw new RuntimeException('Invalid source must fail');
    } catch (YandexMapsParserException) {
    }
}
echo "PASS: pagination, distinct counters, organization identity, missing counter, repeated page\n";

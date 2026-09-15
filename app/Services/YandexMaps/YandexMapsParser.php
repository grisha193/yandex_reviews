<?php

namespace App\Services\YandexMaps;

use Carbon\CarbonImmutable;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class YandexMapsParser
{
    private const ENDPOINT = 'https://yandex.ru/maps/api/business/fetchReviews';
    private const PAGE_SIZE = 50;
    private const MAX_REVIEWS = 650;

    public function parse(string $url, ?callable $progress = null): array
    {
        $businessId = YandexMapsUrl::extractBusinessId($url);

        if ($businessId === null) {
            $url = $this->resolveYandexUrl($url);
            $businessId = YandexMapsUrl::extractBusinessId($url);
        }

        if ($businessId === null) {
            throw new YandexMapsParserException('Не удалось извлечь businessId из ссылки Яндекс.Карт. Для коротких ссылок Яндекс мог не отдать редирект на полную карточку.');
        }

        $client = $this->client();
        $card = $client->get('https://yandex.ru/maps/org/'.$businessId.'/');
        if (! $card->ok()) {
            throw new YandexMapsParserException('Карточка организации недоступна: HTTP '.$card->status());
        }
        $summary = null;
        foreach ($this->extractJsonPayloads($card->body()) as $state) {
            $summary = $this->organizationSummary($state, $businessId);
            if ($summary !== null) break;
        }
        if ($summary === null) {
            throw new YandexMapsParserException('В карточке не найдены точные рейтинг и счётчики организации. Возможна антибот-проверка или изменение структуры Яндекса.');
        }
        $tokenResponse = $client->post(self::ENDPOINT);

        if (! $tokenResponse->ok() || ! is_string($tokenResponse->json('csrfToken'))) {
            throw new YandexMapsParserException('Яндекс не выдал CSRF-токен: '.$this->responsePreview($tokenResponse->status(), $tokenResponse->body()));
        }

        $csrfToken = $tokenResponse->json('csrfToken');
        $allReviews = [];
        $page = 1;

        do {
            $params = $this->signedParams($businessId, $csrfToken, $page);
            $response = $client->get(self::ENDPOINT, $params);

            if ($response->status() === 403 || $response->status() === 429) {
                throw new YandexMapsParserException('Яндекс отклонил запросы к отзывам. Похоже на антибот-защиту или бан сессии.');
            }

            if (! $response->ok() || ! is_array($response->json())) {
                throw new YandexMapsParserException('Яндекс вернул пустой или не-JSON ответ: '.$this->responsePreview($response->status(), $response->body()));
            }

            $payload = $response->json();
            $reviews = $this->extractReviews($payload);
            $pager = $payload['data']['params'] ?? null;
            if (! is_array($pager) || !isset($pager['page'], $pager['totalPages'], $pager['count']) || (int) $pager['page'] !== $page) {
                throw new YandexMapsParserException('Изменилась структура пагинации отзывов Яндекса.');
            }
            $previousCount = count($allReviews);
            foreach ($reviews as $review) {
                $allReviews[$review['external_id']] = $review;
            }
            if (count($allReviews) === $previousCount && $summary['reviews_count'] > count($allReviews)) {
                throw new YandexMapsParserException('Яндекс вернул пустую или повторную страницу отзывов. Сбор не завершён.');
            }

            $loaded = count($allReviews);
            $expected = max($summary['reviews_count'], $loaded);

            if ($progress !== null) {
                $progress((int) min(95, 10 + ($expected > 0 ? ($loaded / $expected) * 80 : 0)));
            }

            usleep(random_int(180_000, 420_000));
            $page++;
        } while ($page <= (int) $pager['totalPages'] && count($allReviews) < self::MAX_REVIEWS);

        $expectedAvailable = min(self::MAX_REVIEWS, (int) $pager['count']);
        if (count($allReviews) < $expectedAvailable) {
            throw new YandexMapsParserException('Сбор неполный: получено '.count($allReviews).' из '.$expectedAvailable.' доступных отзывов.');
        }

        return [
            'name' => $summary['name'],
            'business_id' => $businessId,
            'rating' => $summary['rating'],
            'ratings_count' => $summary['ratings_count'],
            'reviews_count' => $summary['reviews_count'],
            'reviews' => array_slice(array_values($allReviews), 0, self::MAX_REVIEWS),
            'raw' => [
                'business_id' => $businessId,
                'loaded_reviews' => count($allReviews),
                'source' => self::ENDPOINT,
            ],
        ];
    }

    private function organizationSummary(array $node, string $businessId): ?array
    {
        if ((string) ($node['id'] ?? '') === $businessId && isset($node['ratingData'])) {
            $data = $node['ratingData'];
            foreach (['ratingValue', 'ratingCount', 'reviewCount'] as $field) {
                if (!isset($data[$field]) || !is_numeric($data[$field])) return null;
            }
            if ($data['ratingValue'] < 0 || $data['ratingValue'] > 5 || $data['ratingCount'] < 0 || $data['reviewCount'] < 0) return null;
            return [
                'name' => $node['title'] ?? $node['name'] ?? 'Организация Яндекс.Карт',
                'rating' => (float) $data['ratingValue'],
                'ratings_count' => (int) $data['ratingCount'],
                'reviews_count' => (int) $data['reviewCount'],
            ];
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $summary = $this->organizationSummary($child, $businessId);
                if ($summary !== null) return $summary;
            }
        }
        return null;
    }

    private function resolveYandexUrl(string $url): string
    {
        $effectiveUrl = null;

        $response = $this->client()
            ->withOptions([
                'allow_redirects' => ['track_redirects' => true],
                'on_stats' => function (TransferStats $stats) use (&$effectiveUrl): void {
                    $effectiveUrl = (string) $stats->getEffectiveUri();
                },
            ])
            ->get($url);

        if (! $response->ok()) {
            throw new YandexMapsParserException('Не удалось открыть короткую ссылку Яндекс.Карт.');
        }

        if (is_string($effectiveUrl) && $effectiveUrl !== '' && $effectiveUrl !== $url) {
            return $effectiveUrl;
        }

        preg_match('/https:\\/\\/yandex\.[^"]+\\/maps\\/org\\/[^"]+\\/\\d+/u', $response->body(), $match);

        if (isset($match[0])) {
            return stripslashes($match[0]);
        }

        return $url;
    }

    /**
     * Pulls JSON state blobs from Yandex's HTML. Yandex changes script names often, so this
     * intentionally looks for JSON shape instead of a single brittle script id.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractJsonPayloads(string $html): array
    {
        $payloads = [];
        $decodedHtml = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        preg_match_all('~<script\b[^>]*>(.*?)</script>~is', $decodedHtml, $scripts);

        foreach ($scripts[1] as $script) {
            $script = trim($script);

            if ($script === '' || ! Str::contains($script, ['review', 'rating', 'business', 'csrfToken', 'oid'])) {
                continue;
            }

            $direct = json_decode($script, true);
            if (is_array($direct)) {
                $payloads[] = $direct;
                continue;
            }

            preg_match_all('/JSON\.parse\((["\'])(.*?)\1\)/s', $script, $jsonParseMatches, PREG_SET_ORDER);
            foreach ($jsonParseMatches as $match) {
                $jsonString = json_decode($match[1].$match[2].$match[1], true);
                $payload = is_string($jsonString) ? json_decode($jsonString, true) : null;

                if (is_array($payload)) {
                    $payloads[] = $payload;
                }
            }

            $start = strpos($script, '{');
            $end = strrpos($script, '}');

            if ($start !== false && $end !== false && $end > $start) {
                $candidate = substr($script, $start, $end - $start + 1);
                $payload = json_decode($candidate, true);

                if (is_array($payload)) {
                    $payloads[] = $payload;
                }
            }
        }

        return $payloads;
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders([
            'Accept' => 'application/json, text/plain, */*',
            'Accept-Language' => 'ru-RU,ru;q=0.9,en;q=0.8',
            'Origin' => 'https://yandex.ru',
            'Referer' => 'https://yandex.ru/maps/',
            'User-Agent' => config('services.yandex_maps.user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36'),
        ])->timeout(25)->retry(2, 1200, null, throw: false)->withOptions(['cookies' => new CookieJar()]);
    }

    private function signedParams(string $businessId, string $csrfToken, int $page): array
    {
        $reqId = (string) ((int) floor(microtime(true) * 1000)).'-'.random_int(100000000, 999999999).'-codex';
        $sessionId = ((int) floor(microtime(true) * 1000)).'_'.random_int(100000, 999999);

        $params = [
            'ajax' => '1',
            'businessId' => $businessId,
            'csrfToken' => $csrfToken,
            'locale' => 'ru_RU',
            'page' => (string) $page,
            'pageSize' => (string) self::PAGE_SIZE,
            'ranking' => 'by_time',
            'reqId' => $reqId,
            'sessionId' => $sessionId,
        ];

        $params['s'] = (string) $this->djb2Signature($params);

        return $params;
    }

    private function djb2Signature(array $params): int
    {
        ksort($params);
        $source = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $hash = 5381;

        foreach (str_split($source) as $char) {
            $hash = (($hash << 5) + $hash) ^ ord($char);
            $hash &= 0xffffffff;
        }

        return $hash;
    }

    private function responsePreview(int $status, string $body): string
    {
        $preview = Str::limit(trim(preg_replace('/\s+/', ' ', $body) ?: ''), 240);

        return sprintf('HTTP %d%s', $status, $preview !== '' ? ' - '.$preview : '');
    }

    private function extractReviews(array $payload): array
    {
        $rawReviews = Arr::get($payload, 'reviews.items')
            ?? Arr::get($payload, 'data.reviews.items')
            ?? Arr::get($payload, 'data.reviews')
            ?? Arr::get($payload, 'reviews')
            ?? Arr::get($payload, 'data.items')
            ?? Arr::get($payload, 'items')
            ?? [];

        if (is_array($rawReviews) && ! array_is_list($rawReviews)) {
            $rawReviews = $rawReviews['items'] ?? $rawReviews['reviews'] ?? [];
        }

        if (! is_array($rawReviews)) {
            throw new YandexMapsParserException('Поле отзывов имеет неожиданную структуру.');
        }

        $reviews = collect($rawReviews)
            ->filter(fn ($review): bool => is_array($review))
            ->map(fn (array $review): ?array => $this->normalizeReview($review))
            ->filter()
            ->values()
            ->all();

        if ($rawReviews !== [] && $reviews === []) {
            throw new YandexMapsParserException('Поле отзывов найдено, но ни один отзыв не удалось нормализовать. Вероятно, изменилась структура ответа Яндекса.');
        }

        return $reviews;
    }

    private function normalizeReview(array $review): ?array
    {
        $text = Arr::get($review, 'text')
            ?? Arr::get($review, 'body')
            ?? Arr::get($review, 'comment')
            ?? Arr::get($review, 'review.text')
            ?? Arr::get($review, 'reviewText');
        $rating = Arr::get($review, 'rating.value')
            ?? Arr::get($review, 'rating.score')
            ?? Arr::get($review, 'rating.ratingValue')
            ?? Arr::get($review, 'rating')
            ?? Arr::get($review, 'review.rating.value')
            ?? Arr::get($review, 'review.rating')
            ?? Arr::get($review, 'ratingData.ratingValue')
            ?? Arr::get($review, 'stars')
            ?? Arr::get($review, 'stars.value')
            ?? Arr::get($review, 'ratingValue')
            ?? Arr::get($review, 'rate');

        if ($text === null) {
            $text = '';
        }

        if (! is_string($text)) {
            $text = '';
        }

        if (is_array($rating)) {
            $rating = Arr::get($rating, 'value')
                ?? Arr::get($rating, 'score')
                ?? Arr::get($rating, 'ratingValue');
        }

        if (! is_numeric($rating) || $rating < 0 || $rating > 5) {
            return null;
        }

        $externalId = (string) (Arr::get($review, 'id')
            ?? Arr::get($review, 'reviewId')
            ?? Arr::get($review, 'businessReviewId')
            ?? md5(json_encode($review)));
        $date = Arr::get($review, 'date')
            ?? Arr::get($review, 'updatedTime')
            ?? Arr::get($review, 'createdTime')
            ?? Arr::get($review, 'updatedAt')
            ?? Arr::get($review, 'createdAt');
        $author = Arr::get($review, 'author.name')
            ?? Arr::get($review, 'author')
            ?? Arr::get($review, 'user.name')
            ?? Arr::get($review, 'name')
            ?? 'Аноним';

        return [
            'external_id' => $externalId,
            'author_name' => is_string($author) ? $author : 'Аноним',
            'reviewed_at' => $this->parseDate($date),
            'text' => $text,
            'rating' => is_numeric($rating) ? (int) $rating : 0,
            'raw_payload' => $review,
        ];
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (is_numeric($value)) {
            return CarbonImmutable::createFromTimestamp((int) $value);
        }

        if (is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value);
        }

        return null;
    }

}

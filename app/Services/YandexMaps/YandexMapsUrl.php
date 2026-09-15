<?php

namespace App\Services\YandexMaps;

class YandexMapsUrl
{
    public static function isYandexMapsUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $path = (string) parse_url($url, PHP_URL_PATH);

        return $host !== null
            && preg_match('/(^|\.)yandex\.(ru|com|by|kz|uz|com\.tr)$/i', $host)
            && str_starts_with($path, '/maps');
    }

    public static function pendingBusinessId(string $url): string
    {
        return 'pending:'.md5($url);
    }

    public static function extractBusinessId(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! self::isYandexMapsUrl($url)) {
            return null;
        }

        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        if (preg_match('~/org/[^/]+/(\d+)(?:/|$)~', '/'.$path, $matches)) {
            return $matches[1];
        }

        if (preg_match('~/business/(\d+)(?:/|$)~', '/'.$path, $matches)) {
            return $matches[1];
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $oid = $query['oid'] ?? $query['businessId'] ?? null;

        if (is_string($oid) && preg_match('/^\d+$/', $oid)) {
            return $oid;
        }

        $poiUri = data_get($query, 'poi.uri');

        if (is_string($poiUri)) {
            parse_str((string) parse_url($poiUri, PHP_URL_QUERY), $poiQuery);
            $poiOid = $poiQuery['oid'] ?? null;

            if (is_string($poiOid) && preg_match('/^\d+$/', $poiOid)) {
                return $poiOid;
            }
        }

        if (preg_match('/(?:[?&]|%3F|%26)oid(?:=|%3D)(\d+)/i', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }
}

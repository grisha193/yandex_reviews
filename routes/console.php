<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('reviews:demo-user', function (): void {
    \App\Models\User::query()->updateOrCreate(
        ['email' => 'demo@example.com'],
        ['name' => 'Demo User', 'password' => \Illuminate\Support\Facades\Hash::make('password')]
    );

    $this->info('Demo user is ready: demo@example.com / password');
});

Artisan::command('yandex:parse-test {url}', function (string $url): int {
    /** @var \App\Services\YandexMaps\YandexMapsParser $parser */
    $parser = app(\App\Services\YandexMaps\YandexMapsParser::class);

    try {
        $result = $parser->parse($url, function (int $progress): void {
            echo "progress: {$progress}%\n";
        });
    } catch (\Throwable $exception) {
        $this->error($exception->getMessage());

        return \Symfony\Component\Console\Command\Command::FAILURE;
    }

    $this->info('Name: '.$result['name']);
    $this->info('Business ID: '.$result['business_id']);
    $this->info('Rating: '.$result['rating']);
    $this->info('Ratings count: '.$result['ratings_count']);
    $this->info('Reviews count: '.$result['reviews_count']);
    $this->info('Loaded reviews: '.count($result['reviews']));

    return \Symfony\Component\Console\Command\Command::SUCCESS;
});

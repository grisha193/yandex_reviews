<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('yandex_business_id');
            $table->text('yandex_url');
            $table->string('name')->nullable();
            $table->decimal('rating', 3, 2)->nullable();
            $table->unsignedInteger('ratings_count')->default(0);
            $table->unsignedInteger('reviews_count')->default(0);
            $table->string('status')->default('queued')->index();
            $table->unsignedTinyInteger('parse_progress')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('parsed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'yandex_business_id']);
        });

        Schema::create('reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->string('author_name')->nullable();
            $table->timestamp('reviewed_at')->nullable()->index();
            $table->text('text')->nullable();
            $table->unsignedTinyInteger('rating')->default(0);
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'external_id']);
        });

        Schema::create('organization_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->decimal('rating', 3, 2)->nullable();
            $table->unsignedInteger('ratings_count')->default(0);
            $table->unsignedInteger('reviews_count')->default(0);
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_snapshots');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('organizations');
    }
};

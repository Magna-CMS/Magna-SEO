<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('magna_seo_meta', function (Blueprint $table): void {
            $table->id();

            // Polymorphic owner. String id accommodates ULID (Entry) and integer
            // (DocPage) keys alike. Unique so a subject has exactly one meta row.
            $table->string('seoable_type');
            $table->string('seoable_id');

            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('canonical_url', 2048)->nullable();

            $table->boolean('robots_index')->default(true);
            $table->boolean('robots_follow')->default(true);
            $table->json('robots_advanced')->nullable();

            $table->string('og_title')->nullable();
            $table->text('og_description')->nullable();
            $table->string('og_image_id')->nullable();

            $table->string('twitter_card')->nullable();
            $table->string('twitter_title')->nullable();
            $table->text('twitter_description')->nullable();
            $table->string('twitter_image_id')->nullable();

            $table->json('focus_keywords')->nullable();
            $table->json('schema_overrides')->nullable();

            $table->json('analysis_cache')->nullable();
            $table->timestamp('analysis_computed_at')->nullable();

            $table->timestamps();

            $table->unique(['seoable_type', 'seoable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('magna_seo_meta');
    }
};

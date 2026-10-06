<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_redirects', function (Blueprint $table): void {
            $table->id();

            // The path (or pattern) being redirected FROM, always stored without
            // the origin and with a leading slash, so matching is host-agnostic.
            $table->string('source_path', 2048);
            $table->string('match_type', 10)->default('exact');

            // Null for a 410 Gone, which deliberately has no destination.
            $table->string('target', 2048)->nullable();
            $table->unsignedSmallInteger('status_code')->default(301);

            $table->boolean('preserve_query')->default(true);
            $table->boolean('is_active')->default(true);

            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->string('notes', 500)->nullable();

            $table->timestamps();

            // Exact matches are looked up by a hash of the normalised path: the
            // full 2048-character column is too wide to index on MySQL.
            $table->string('source_hash', 64)->index();
            $table->index(['is_active', 'match_type']);
        });

        Schema::create('seo_not_found_log', function (Blueprint $table): void {
            $table->id();

            // One row per distinct path, counted — an append-only log of every hit
            // would let a crawler hitting nonsense URLs fill the disk.
            $table->string('path', 2048);
            $table->string('path_hash', 64)->unique();

            $table->string('last_referrer', 2048)->nullable();
            $table->unsignedInteger('hits')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_not_found_log');
        Schema::dropIfExists('seo_redirects');
    }
};

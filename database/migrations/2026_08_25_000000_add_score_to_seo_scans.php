<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_scans', function (Blueprint $table): void {
            // The scorecard result at the time of the scan. Stored rather than
            // recomputed, because a trend line built from today's content would
            // show today's score at every point on it.
            $table->unsignedTinyInteger('score')->nullable()->after('issue_count');

            // Failures per check, so a pass rate can be shown without keeping
            // every individual issue row forever.
            $table->json('check_stats')->nullable()->after('score');
        });
    }

    public function down(): void
    {
        Schema::table('seo_scans', function (Blueprint $table): void {
            $table->dropColumn(['score', 'check_stats']);
        });
    }
};

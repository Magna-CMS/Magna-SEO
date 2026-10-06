<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('magna_seo_meta', function (Blueprint $table): void {
            // Marks the pages a site actually wants to rank for. Indexed because
            // link suggestions and scan weighting both filter on it.
            $table->boolean('is_cornerstone')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('magna_seo_meta', function (Blueprint $table): void {
            $table->dropColumn('is_cornerstone');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_scans', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('url_count')->default(0);
            $table->unsignedInteger('issue_count')->default(0);
            $table->timestamps();
        });

        Schema::create('seo_scan_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seo_scan_id')->constrained('seo_scans')->cascadeOnDelete();
            $table->string('source');
            $table->string('subject_key');
            $table->string('url', 2048)->nullable();
            $table->string('check');
            $table->string('severity', 20);
            $table->text('message');
            $table->timestamps();

            $table->index(['seo_scan_id', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_scan_issues');
        Schema::dropIfExists('seo_scans');
    }
};

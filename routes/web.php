<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Magna\Seo\Http\Controllers\IndexNowKeyController;
use Magna\Seo\Http\Controllers\LlmsTxtController;
use Magna\Seo\Http\Controllers\RedirectHintController;
use Magna\Seo\Http\Controllers\RobotsController;
use Magna\Seo\Http\Controllers\SitemapController;

// SEO owns these public URLs explicitly so they win over any renderer plugin's
// catch-all and work on a headless or docs-only install.
Route::get('/robots.txt', RobotsController::class)->name('seo.robots');

Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('seo.sitemap.index');

// A curated markdown map of the site for language models. Convention, not
// standard — see LlmsTxtGenerator for what it does and does not do.
Route::get('/llms.txt', [LlmsTxtController::class, 'index'])->name('seo.llms.index');
Route::get('/llms-full.txt', [LlmsTxtController::class, 'full'])->name('seo.llms.full');

// Paginated child sitemaps. Declared before the unsuffixed form so "-{digits}"
// is read as a page number; a source handle may therefore not end in one, which
// SeoSourceRegistry enforces at registration time.
Route::get('/sitemap-{source}-{page}.xml', [SitemapController::class, 'child'])
    ->where('source', '[a-z0-9-]+')
    ->where('page', '[0-9]+')
    ->name('seo.sitemap.child.page');

Route::get('/sitemap-{source}.xml', [SitemapController::class, 'child'])
    ->where('source', '[a-z0-9-]+')
    ->name('seo.sitemap.child');

// Redirect lookup for headless frontends, which render their own 404s and never
// reach this app's middleware. Throttled, since it is an unauthenticated read.
Route::get('/seo/redirect-hint', RedirectHintController::class)
    ->middleware('throttle:60,1')
    ->name('seo.redirect.hint');

// IndexNow key verification file. The hex constraint cannot match robots.txt or
// the sitemap routes above.
Route::get('/{key}.txt', IndexNowKeyController::class)
    ->where('key', '[0-9a-f]{8,128}')
    ->name('seo.indexnow.key');

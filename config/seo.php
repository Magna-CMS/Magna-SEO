<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Entry field mapping
    |--------------------------------------------------------------------------
    |
    | Content entries have no fixed "title"/"excerpt"/"image" column — every
    | content type defines its own fields. When SEO derives meta from a delivery
    | payload it looks for the first present handle in each list below, so a site
    | with unconventional field names can retune the mapping without code.
    |
    */

    'entry' => [
        'title_fields' => ['title', 'name', 'heading'],
        'excerpt_fields' => ['excerpt', 'summary', 'description', 'dek', 'subtitle'],
        'body_fields' => ['body', 'content', 'text'],
        'image_fields' => ['og_image', 'featured_image', 'image', 'cover', 'thumbnail'],

        /*
        | Entry URL patterns
        |
        | Core owns no entry-to-URL map — the frontend that renders an entry does.
        | Map each content type handle to the path its frontend serves it at; `*`
        | is the fallback for types not listed. Available tokens: {type}, {slug},
        | {id}, {locale}, {year}, {month}, {day} (dates from published_at).
        |
        | A content type with no pattern has no public URL, and SEO reads that as
        | "not publicly renderable": such entries are never given a canonical, are
        | excluded from sitemaps, and resolve to noindex. Removing `*` therefore
        | makes the mapping strictly opt-in.
        |
        | Examples:
        |   'post' => '/blog/{slug}',
        |   'news' => '/{year}/{month}/{slug}',
        */

        'url_patterns' => [
            '*' => '/{type}/{slug}',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Meta description length
    |--------------------------------------------------------------------------
    |
    | Word-safe truncation length for a description auto-derived from body text
    | when no explicit description/excerpt exists.
    |
    */

    'description_length' => 155,

    /*
    |--------------------------------------------------------------------------
    | Sitemaps
    |--------------------------------------------------------------------------
    |
    | Rendered sitemap XML is cached for this many seconds. A short-to-medium
    | window keeps the endpoint cheap; content changes surface on expiry. (A
    | surrogate-key purge on publish is a later refinement.)
    |
    */

    'sitemap' => [
        'cache_ttl' => 3600,

        /*
        | URLs per child sitemap. Deliberately well under the protocol's 50 000
        | maximum: smaller files reprocess faster after a content change and keep
        | a failure contained to one page instead of a whole source.
        */
        'max_urls' => 5000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Site scan
    |--------------------------------------------------------------------------
    |
    | The static scan flags pages whose body falls below this word count as thin.
    | Tune it for content that is legitimately terse (e.g. reference docs).
    |
    */

    'scan' => [
        'thin_content_words' => 100,

        /*
        | Cron expression for the automatic scan, or null to only scan on demand.
        | The scheduled run goes through the queue, so it never blocks the
        | scheduler; it needs a worker to be running.
        */
        'schedule' => '0 3 * * 0',

        /*
        | Page-weight thresholds, measured from the markup with no network call.
        | A page over double "max_html_bytes", or over "max_inline_bytes" of
        | inlined script and style, is reported as a warning; the DOM-node and
        | single-threshold cases are notices. Raise these for a site whose pages
        | are legitimately long (a reference manual), lower them for a site that
        | is mostly short posts.
        */
        'page_weight' => [
            'max_html_bytes' => 150000,
            'max_inline_bytes' => 50000,
            'max_dom_nodes' => 1500,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | llms.txt
    |--------------------------------------------------------------------------
    |
    | /llms.txt is a curated markdown map of the site for language models, read
    | at inference time. It is a convention, not a crawling standard: whether an
    | AI platform may use the site at all is governed by robots.txt, configured
    | in SEO settings.
    |
    | "full_max_characters" caps /llms-full.txt, which inlines page text. The
    | point of the file is to fit in a context window, so a site larger than the
    | cap is truncated with a note pointing back at /llms.txt.
    |
    */

    'llms' => [
        'cache_ttl' => 3600,
        'full_max_characters' => 500000,
    ],

    /*
    |--------------------------------------------------------------------------
    | robots.txt
    |--------------------------------------------------------------------------
    |
    | A static file here is served by the web server before PHP runs and shadows
    | the dynamic /robots.txt route entirely, so the scan checks it. Override the
    | path if your document root is not public/, or set it to null to skip the
    | check on an install that manages robots.txt outside the application.
    |
    */

    'robots' => [
        'static_path' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redirects
    |--------------------------------------------------------------------------
    |
    | How many rules a chain may be collapsed through before the walker stops.
    | Five is generous: crawlers give up well before that, so a site needing more
    | has a rule-set problem the ceiling should surface rather than hide.
    |
    */

    'redirects' => [
        'max_hops' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | 404 logging
    |--------------------------------------------------------------------------
    |
    | Distinct 404 paths are counted so editors can turn the ones that matter
    | into redirects. Because anyone can request unlimited distinct URLs, the log
    | is bounded two ways: "sample_rate" (1.0 = record everything, 0.1 = roughly
    | one in ten) and "max_rows", enforced by evicting the least recently seen
    | paths. Prefixes listed here are never logged.
    |
    */

    'not_found' => [
        'enabled' => true,
        'sample_rate' => 1.0,
        'max_rows' => 5000,
        'ignore_prefixes' => ['/admin', '/livewire', '/api', '/build', '/storage', '/vendor'],
    ],

    /*
    |--------------------------------------------------------------------------
    | IndexNow
    |--------------------------------------------------------------------------
    |
    | The endpoint URLs are submitted to for instant indexing. A single, fixed,
    | trusted host — submissions only ever POST the site's own URLs to it.
    |
    */

    'indexnow' => [
        'endpoint' => 'https://api.indexnow.org/indexnow',

        /*
        | Automatic submission (enabled in SEO settings) buffers changed URLs and
        | flushes them as one batch after "batch_delay" seconds, so a bulk publish
        | is one request rather than hundreds. "batch_window" is how long a URL may
        | sit in the buffer before it is considered stale.
        */
        'batch_delay' => 60,
        'batch_window' => 3600,
    ],

];

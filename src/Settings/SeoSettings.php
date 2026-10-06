<?php

declare(strict_types=1);

namespace Magna\Seo\Settings;

use Magna\Settings\Attributes\Secret;
use Magna\Settings\Settings;

/**
 * Site-wide SEO defaults. Per-entity overrides in magna_seo_meta always win;
 * these are the fallback applied when a subject has no override.
 *
 * Settings::group() derives "seo" from the class name, so these persist under
 * the "seo" settings group.
 */
class SeoSettings extends Settings
{
    /** Site name used by the %%sitename%% template token. Empty falls back to the CMS site name. */
    public string $site_name = '';

    /** Separator rendered for the %%sep%% token in titles. */
    public string $title_separator = '-';

    /** Default title template applied when a subject/type defines none. */
    public string $default_title_template = '%%title%% %%sep%% %%sitename%%';

    /** Default meta-description template. %%excerpt%% falls back to the subject's excerpt/body. */
    public string $default_description_template = '%%excerpt%%';

    /**
     * Per-content-type overrides of the defaults above, keyed by content type
     * handle. A blog post and a documentation page rarely want the same title
     * format, and forcing one on both means overriding it by hand on every page.
     *
     * Recognised keys per type: title_template, description_template,
     * robots_index (false to keep a type out of the index entirely — useful for
     * types that only exist to be embedded), schema_type.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $type_defaults = [];

    /** Site identity for structured data: 'organization' or 'person'. */
    public string $knowledge_graph_type = 'organization';

    /** Organisation display name (used when knowledge_graph_type = organization). */
    public string $organization_name = '';

    /** Media path to the organisation logo for Organization schema. */
    public string $organization_logo = '';

    /** Person display name (used when knowledge_graph_type = person). */
    public string $person_name = '';

    /**
     * Absolute URLs to the site's social profiles, emitted as schema sameAs.
     *
     * @var list<string>
     */
    public array $social_profiles = [];

    /** The @handle for twitter:site (with or without the leading @). */
    public string $twitter_site = '';

    /**
     * Default twitter:card type. Blank picks one automatically: a large image
     * card when the page has an image, a summary card when it does not.
     */
    public string $twitter_card_type = '';

    /**
     * Site search URL with `{search_term_string}` as the placeholder, e.g.
     * "https://example.com/search?q={search_term_string}". When set, the WebSite
     * schema node advertises a SearchAction. Blank omits it.
     */
    public string $search_url_template = '';

    /** Media id (ULID) of the default social share image, used when a subject has none. */
    public string $default_og_image = '';

    /** Emit noindex on non-production environments so staging never gets indexed. */
    public bool $noindex_non_production = true;

    /**
     * Publish /llms.txt — a curated markdown map of the site for language models.
     * It is a convention read at inference time, not an indexing signal: whether
     * an AI platform may use the site is decided by the crawler settings below.
     */
    public bool $llms_txt_enabled = true;

    /** One-paragraph description of the site, emitted as the llms.txt summary. */
    public string $llms_txt_summary = '';

    /** Also publish /llms-full.txt, with each page's text inlined. */
    public bool $llms_full_enabled = false;

    /**
     * Let AI crawlers collect pages for model training (GPTBot, ClaudeBot,
     * Google-Extended, CCBot, …). Blocking costs no search traffic.
     */
    public bool $allow_ai_training = true;

    /**
     * Let AI search crawlers index the site (OAI-SearchBot, Claude-SearchBot,
     * PerplexityBot). Blocking removes the site from AI-assistant answers.
     */
    public bool $allow_ai_search = true;

    /**
     * Let assistants fetch a page live when a person asks about that URL
     * (ChatGPT-User, Claude-User, Perplexity-User). Blocking breaks the
     * experience for someone who deliberately linked to you.
     */
    public bool $allow_ai_user_fetch = true;

    /** IndexNow key, hosted at /{key}.txt and echoed in submissions. Empty = not yet generated. */
    public string $indexnow_key = '';

    /**
     * Submit a URL to IndexNow automatically whenever its entry is published,
     * updated or deleted. Off by default: it is an outbound request the site
     * owner should opt into.
     */
    public bool $indexnow_auto_submit = false;

    /**
     * Google Search Console. The property URL is exactly as it appears in Search
     * Console ("https://example.com/" or "sc-domain:example.com"). The client
     * secret and refresh token are encrypted at rest.
     */
    public string $search_console_site = '';

    public string $search_console_client_id = '';

    #[Secret]
    public string $search_console_client_secret = '';

    #[Secret]
    public string $search_console_refresh_token = '';

    /** Bing Webmaster Tools API key, encrypted at rest. */
    #[Secret]
    public string $bing_webmaster_api_key = '';

    /**
     * Chrome UX Report API key, for Core Web Vitals field data. Optional: it is
     * the only feature here that makes a network call per URL, and CrUX only has
     * data for pages with enough real traffic.
     */
    #[Secret]
    public string $crux_api_key = '';

    /** Set once the setup wizard has been completed, so it stops prompting. */
    public bool $setup_completed = false;

    /** Google Search Console site-verification token (google-site-verification meta). */
    public string $google_site_verification = '';

    /** Bing Webmaster site-verification token (msvalidate.01 meta). */
    public string $bing_site_verification = '';

    /** Pinterest site-verification token (p:domain_verify meta). */
    public string $pinterest_site_verification = '';

    /** Yandex site-verification token (yandex-verification meta). */
    public string $yandex_site_verification = '';
}

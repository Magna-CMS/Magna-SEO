<?php

declare(strict_types=1);

namespace Magna\Seo\Support;

/**
 * How to fix each finding, in words an editor can act on without searching the
 * web for what the finding meant.
 *
 * A check's message states the problem ("3 images have no alt text"); this
 * states the remedy. They are kept apart because the message is per-page and
 * carries counts, while the remedy is the same every time and would be noise
 * repeated on every row.
 *
 * One registry rather than a method on each check, because findings come from
 * two families — scan checks and analysis checks — and both are keyed the same
 * way. A finding with no entry here simply shows no instructions, so adding a
 * check never breaks this.
 */
final class FixInstructions
{
    /**
     * @var array<string, string>
     */
    private const INSTRUCTIONS = [
        // ── Scan findings ────────────────────────────────────────────────
        'missing-title' => 'Open the page and give it a title in the SEO panel. Lead with what the page is about — the first 50 or so characters are what people see in search results, and a title starting with your site name wastes them.',

        'missing-description' => 'Write a meta description in the SEO panel: one or two sentences, roughly 120–160 characters, describing what the reader gets. It is not a ranking factor directly, but it is the sales pitch that decides whether anyone clicks.',

        'thin-content' => 'This page has too little text to answer anything properly. Either expand it so it genuinely covers its subject, or merge it into a fuller page and redirect this URL there. Deleting weak pages usually helps a site more than keeping them.',

        'duplicate-title' => 'Two or more pages share this title, so search engines cannot tell them apart and will likely show only one. Make each title describe what is unique about that page — or, if the pages really are duplicates, merge them and redirect.',

        'duplicate-description' => 'Several pages share this description. Write a distinct one for each; a copied description makes every result look the same to a reader choosing which to click.',

        'hreflang-reciprocity' => 'This page points at a translation that does not point back. Every language version must list every other, including itself. Fix the alternates on the page it links to, or remove the alternate here.',

        'canonical-conflict' => 'Two pages claim the same canonical URL, so all but one will be dropped from the index. Give each page its own URL, or — if one really is a duplicate — leave the canonical pointing at the original and let it be dropped deliberately.',

        'orphan-page' => 'Nothing on the site links here, so this page inherits no authority and is hard to discover. Add a link to it from a related page that people actually reach — the more relevant the linking page, the more it helps.',

        'missing-image-alt' => 'Open the page and describe each image in its alt field. Describe the content, not the file: "CEO signing the merger agreement" beats "IMG_4821". Leave alt empty only for images that are purely decorative.',

        'heavy-page' => 'Reduce what the page ships. Move inline scripts and styles into files the browser can cache, compress or resize oversized images, and give every image a width and height so nothing jumps as it loads. Split very long pages into several.',

        'redirect-chain' => 'This redirect passes through another before arriving. Edit it to point straight at the final destination — search engines discount each extra hop, and some stop following after a few.',

        'redirect-loop' => 'These redirects point back at each other, so neither can ever resolve. Open the redirect list and decide which URL is the real destination, then delete or repoint the other.',

        'sitemap-redirect' => 'A URL in the sitemap redirects instead of answering directly, which wastes crawl budget and contradicts the sitemap. Either remove the redirect, or update the content so the sitemap lists the destination.',

        'schema-invalid' => 'The structured data on this page is missing something a rich result needs. Usually it is a required field left blank — a headline, an image, an author. Fill it in on the page, then re-run the scan.',

        'robots-sitemap-conflict' => 'A static public/robots.txt is being served before the application, so the generated one is ignored. Run "php artisan seo:robots" to move it aside; the command renames it rather than deleting it, so nothing is lost.',

        // ── Editor analysis findings ─────────────────────────────────────
        'keyword-in-title' => 'Work the focus keyword into the title, as close to the front as reads naturally. If it does not fit, the keyword is probably wrong for this page.',

        'keyword-in-description' => 'Use the focus keyword once in the meta description. It is bolded in results when it matches the search, which draws the eye.',

        'keyword-in-slug' => 'Put the focus keyword in the URL slug. Change it before publishing — changing a slug afterwards means setting up a redirect and losing some accumulated value.',

        'keyword-in-opening' => 'Mention the focus keyword in the opening paragraph, so both a reader and a crawler can confirm within a sentence that they are in the right place.',

        'keyword-in-heading' => 'Use the focus keyword in the H1, and in a subheading where it fits. Do not force it into every heading — that reads as spam to a person and to a ranking system.',

        'keyword-density' => 'Aim for the keyword appearing naturally, roughly half a percent to two and a half percent of the words. Below that, the page reads as if it is about something else; far above it, as if it is written for a machine. Synonyms and related phrasing count for the reader even when they do not count here.',

        'content-length' => 'Add depth: answer the questions a reader arrives with, and the ones they have next. Length is not the goal — covering the subject is, and covering it takes words.',

        'title-length' => 'Keep the title roughly 30–60 characters. Longer is cut off in results; much shorter usually means the title is not saying enough to earn the click.',

        'description-length' => 'Aim for roughly 120–160 characters. Shorter wastes the space you are given; longer is truncated mid-sentence.',

        'heading-structure' => 'Use exactly one H1, then step down a level at a time — H2 under H1, H3 under H2. Long pages need subheadings so a reader can scan; skipped levels confuse screen readers and crawlers alike.',

        'links' => 'Link out to related pages on this site so a reader has somewhere to go and the page passes authority onward. Citing an outside source where you make a factual claim adds credibility rather than leaking it.',

        'image-alt' => 'Describe every meaningful image in its alt field. If an image is decorative only, leave the alt attribute present and empty so screen readers skip it.',

        'paragraph-length' => 'Break long paragraphs up. A block that fills a phone screen gets skipped; two or three sentences per paragraph reads much better.',

        'sentence-length' => 'Split the longest sentences. Anything past about twenty words is usually two ideas that would be clearer apart.',

        'passive-voice' => 'Rewrite some sentences so the subject does the action: "the team shipped it", not "it was shipped by the team". Active writing is shorter and clearer.',

        'transition-words' => 'Connect your points explicitly — however, therefore, for example, in contrast. Transitions turn a list of statements into an argument a reader can follow.',

        'reading-ease' => 'Simplify: shorter sentences, plainer words, fewer clauses per sentence. Aim for prose a reader can follow at speed, not prose that proves you know the subject.',

        'page-weight' => 'Move inline scripts and styles into cacheable files, resize images to the size they are displayed at, and set width and height on every image. Heavy pages lose readers before they read anything.',
    ];

    /**
     * The remedy for one finding, or null when none is written for it.
     */
    public static function for(string $check): ?string
    {
        return self::INSTRUCTIONS[$check] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::INSTRUCTIONS;
    }
}

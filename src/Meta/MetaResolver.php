<?php

declare(strict_types=1);

namespace Magna\Seo\Meta;

use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Head\HeadPayload;
use Magna\Seo\Models\SeoMeta;
use Magna\Seo\Settings\SeoSettings;
use Magna\Seo\Subjects\SeoImage;
use Magna\Seo\Subjects\SeoSubject;
use Magna\Seo\Support\HreflangSet;

/**
 * The one place meta is decided. Given a subject, its optional per-entity
 * override and the site defaults, it produces a fully-resolved {@see HeadPayload}
 * that both the HTML renderer and the delivery serialiser consume — so a page,
 * a doc and the API can never disagree.
 *
 * Precedence for every field: per-entity override → template/default → nothing.
 * Values are returned raw; escaping happens later, in the renderer.
 */
final class MetaResolver
{
    public function __construct(
        private readonly TemplateEngine $engine,
        private readonly IndexabilityPolicy $indexability,
        private readonly int $descriptionLength = 155,
    ) {}

    public function resolve(SeoSubject $subject, ?SeoMeta $override, SeoSettings $settings): HeadPayload
    {
        $tokens = $this->tokens($subject, $settings);

        $title = $this->resolveTitle($subject, $override, $settings, $tokens);
        $description = $this->resolveDescription($subject, $override, $settings, $tokens);

        // A content type can be excluded from the index wholesale — some types
        // exist only to be embedded, and indexing each as a thin page hurts.
        if (! TypeDefaults::isIndexable($subject, $settings)) {
            $subject = $subject->withIndexable(false);
        }
        $canonical = $this->resolveCanonical($subject, $override);
        $image = $subject->primaryImage();

        $names = ['robots' => $this->indexability->robotsContent($subject, $override, $settings)];
        if ($description !== null) {
            $names['description'] = $description;
        }

        foreach ($this->verificationTags($settings) as $name => $content) {
            $names[$name] = $content;
        }

        $properties = $this->openGraph($subject, $override, $settings, $title, $description, $canonical, $image);
        $this->twitter($names, $override, $settings, $title, $description, $subject->socialImage('twitter'));

        $links = [];
        if ($canonical !== null) {
            $links['canonical'] = $canonical;
        }

        return new HeadPayload(
            title: $title,
            metaNames: $names,
            metaProperties: $properties,
            links: $links,
            alternates: HreflangSet::forSubject($subject),
        );
    }

    /**
     * Site-wide search-engine ownership-verification meta tags, emitted on every
     * page when a token is configured.
     *
     * @return array<string, string>
     */
    private function verificationTags(SeoSettings $settings): array
    {
        $tags = [];

        if ($settings->google_site_verification !== '') {
            $tags['google-site-verification'] = $settings->google_site_verification;
        }
        if ($settings->bing_site_verification !== '') {
            $tags['msvalidate.01'] = $settings->bing_site_verification;
        }
        if ($settings->pinterest_site_verification !== '') {
            $tags['p:domain_verify'] = $settings->pinterest_site_verification;
        }
        if ($settings->yandex_site_verification !== '') {
            $tags['yandex-verification'] = $settings->yandex_site_verification;
        }

        return $tags;
    }

    /**
     * @param  array<string, string>  $tokens
     */
    private function resolveTitle(SeoSubject $subject, ?SeoMeta $override, SeoSettings $settings, array $tokens): string
    {
        $overrideTitle = $this->trimmed($override?->title);
        if ($overrideTitle !== null) {
            return $overrideTitle;
        }

        $typeTemplate = TypeDefaults::titleTemplate($subject, $settings);
        $template = $typeTemplate !== '' ? $typeTemplate : '%%title%%';

        $rendered = $this->engine->render($template, $tokens);

        // Strip a separator/space left dangling when an optional token (e.g.
        // sitename) resolved to empty, so "Title -" becomes "Title".
        $rendered = trim($rendered, " \t\n\r\0\x0B".$settings->title_separator);

        return $rendered !== '' ? $rendered : $subject->title;
    }

    /**
     * @param  array<string, string>  $tokens
     */
    private function resolveDescription(SeoSubject $subject, ?SeoMeta $override, SeoSettings $settings, array $tokens): ?string
    {
        $overrideDescription = $this->trimmed($override?->description);
        if ($overrideDescription !== null) {
            return $overrideDescription;
        }

        $rendered = $this->engine->render(TypeDefaults::descriptionTemplate($subject, $settings), $tokens);

        return $rendered !== '' ? $rendered : null;
    }

    private function resolveCanonical(SeoSubject $subject, ?SeoMeta $override): ?string
    {
        $overrideCanonical = $this->trimmed($override?->canonical_url);
        if ($overrideCanonical !== null && $this->isSafeUrl($overrideCanonical)) {
            return $overrideCanonical;
        }

        // A missing or unsafe override falls back to the subject's own URL rather
        // than dropping the canonical entirely.
        return ($subject->url !== '' && $this->isSafeUrl($subject->url)) ? $subject->url : null;
    }

    /**
     * A canonical/og:url must be an http(s) URL or a root-relative path. This
     * rejects an author-set override with a dangerous scheme (javascript:, data:)
     * before it is emitted into a <link> or og:url.
     */
    private function isSafeUrl(string $url): bool
    {
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return true;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }

    /**
     * @param  array<string, string>  $names  passed by reference; twitter:* are name-based metas
     */
    private function twitter(array &$names, ?SeoMeta $override, SeoSettings $settings, string $title, ?string $description, ?SeoImage $image): void
    {
        $names['twitter:card'] = $this->cardType($override, $settings, $image);
        $names['twitter:title'] = $this->trimmed($override?->twitter_title) ?? $title;

        $twitterDescription = $this->trimmed($override?->twitter_description) ?? $description;
        if ($twitterDescription !== null) {
            $names['twitter:description'] = $twitterDescription;
        }

        $site = ltrim(trim($settings->twitter_site), '@');
        if ($site !== '') {
            $names['twitter:site'] = '@'.$site;
        }

        if ($image !== null) {
            $names['twitter:image'] = $image->url;
        }
    }

    /**
     * Per-entity override, then the site default, then an automatic choice. Both
     * configured values are checked against the card types Twitter actually
     * accepts, so a typo degrades to the automatic pick instead of emitting an
     * invalid card.
     */
    private function cardType(?SeoMeta $override, SeoSettings $settings, ?SeoImage $image): string
    {
        $allowed = ['summary', 'summary_large_image', 'app', 'player'];

        foreach ([$this->trimmed($override?->twitter_card), $this->trimmed($settings->twitter_card_type)] as $candidate) {
            if ($candidate !== null && in_array($candidate, $allowed, true)) {
                return $candidate;
            }
        }

        return $image !== null ? 'summary_large_image' : 'summary';
    }

    /**
     * @return array<string, string>
     */
    private function openGraph(SeoSubject $subject, ?SeoMeta $override, SeoSettings $settings, string $title, ?string $description, ?string $canonical, ?SeoImage $image): array
    {
        $properties = [
            'og:title' => $this->trimmed($override?->og_title) ?? $title,
            'og:type' => $subject->type === SubjectType::Article ? 'article' : 'website',
            'og:locale' => $subject->locale,
        ];

        $ogDescription = $this->trimmed($override?->og_description) ?? $description;
        if ($ogDescription !== null) {
            $properties['og:description'] = $ogDescription;
        }

        if ($canonical !== null) {
            $properties['og:url'] = $canonical;
        }

        if ($settings->site_name !== '') {
            $properties['og:site_name'] = $settings->site_name;
        }

        if ($image !== null) {
            $properties['og:image'] = $image->url;
            if ($image->width !== null) {
                $properties['og:image:width'] = (string) $image->width;
            }
            if ($image->height !== null) {
                $properties['og:image:height'] = (string) $image->height;
            }
            if ($image->alt !== null && $image->alt !== '') {
                $properties['og:image:alt'] = $image->alt;
            }
        }

        if ($subject->type === SubjectType::Article) {
            if ($subject->publishedAt !== null) {
                $properties['article:published_time'] = $subject->publishedAt->format('c');
            }
            $properties['article:modified_time'] = $subject->updatedAt->format('c');
        }

        return $properties;
    }

    /**
     * @return array<string, string>
     */
    private function tokens(SeoSubject $subject, SeoSettings $settings): array
    {
        return [
            'title' => $subject->title,
            'sitename' => $settings->site_name,
            'sep' => $settings->title_separator,
            'excerpt' => $subject->excerpt ?? $this->summarise($subject->plainText),
            'date' => $subject->publishedAt?->format('Y-m-d') ?? '',
            'locale' => $subject->locale,
        ];
    }

    /**
     * Word-safe truncation of body text for a fallback description.
     */
    private function summarise(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '' || mb_strlen($text) <= $this->descriptionLength) {
            return $text;
        }

        $clipped = mb_substr($text, 0, $this->descriptionLength);
        $lastSpace = mb_strrpos($clipped, ' ');
        if ($lastSpace !== false && $lastSpace > 0) {
            $clipped = mb_substr($clipped, 0, $lastSpace);
        }

        return rtrim($clipped).'…';
    }

    private function trimmed(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}

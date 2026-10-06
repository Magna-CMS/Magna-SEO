<?php

declare(strict_types=1);

namespace Magna\Seo\Llms;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Magna\Seo\Contracts\SeoSubjectSource;
use Magna\Seo\Registry\SeoSourceRegistry;
use Magna\Seo\Settings\SeoSettings;
use Magna\Seo\Subjects\SeoSubject;
use Magna\Seo\Support\SeoMetaRepository;
use Throwable;

/**
 * Builds `/llms.txt` — a curated markdown map of the site for language models.
 *
 * What this is, and is not, matters for expectations. llms.txt is a convention
 * (proposed in 2024, adopted mainly by documentation platforms), not a crawling
 * or indexing standard: no search or AI provider treats it as a signal for
 * *whether* to index a site. It is read at inference time by a model or agent
 * that has already been pointed at the site, and it exists because a model
 * given raw HTML wastes most of its context on navigation and markup. What
 * actually governs AI crawler access is robots.txt — see RobotsController.
 *
 * The format is deliberately plain: an H1 with the site name, an optional
 * blockquote summary, then one `##` section per content source listing
 * `- [Title](url): description`. Same content set as the sitemap, so the two can
 * never describe different sites.
 */
final class LlmsTxtGenerator
{
    private const CACHE_KEY = 'seo:llms:txt';

    private const FULL_CACHE_KEY = 'seo:llms:full';

    public function __construct(
        private readonly SeoSourceRegistry $registry,
        private readonly SeoMetaRepository $meta = new SeoMetaRepository,
    ) {}

    /**
     * The index: every indexable page as a titled, described link.
     */
    public function index(): string
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_string($cached)) {
            return $cached;
        }

        $settings = SeoSettings::get();
        $document = $this->header($settings);
        $complete = true;

        foreach ($this->registry->all() as $handle => $source) {
            $subjects = $this->collect($handle, $source);

            if ($subjects === null) {
                $complete = false;

                continue;
            }

            if ($subjects === []) {
                continue;
            }

            $document .= "\n## ".$this->escape($source->label())."\n\n";

            foreach ($subjects as $subject) {
                $document .= $this->line($subject);
            }
        }

        if ($complete) {
            Cache::put(self::CACHE_KEY, $document, $this->ttl());
        }

        return $document;
    }

    /**
     * `/llms-full.txt`: the same map with each page's text inlined, for a model
     * that would otherwise have to fetch every URL. Capped hard, because the
     * whole point is to fit in a context window — a site that exceeds the cap is
     * truncated with a note rather than silently cut off.
     */
    public function full(): string
    {
        $cached = Cache::get(self::FULL_CACHE_KEY);

        if (is_string($cached)) {
            return $cached;
        }

        $settings = SeoSettings::get();
        $document = $this->header($settings);
        $budget = $this->maxCharacters();
        $truncated = false;
        $complete = true;

        foreach ($this->registry->all() as $handle => $source) {
            $subjects = $this->collect($handle, $source);

            if ($subjects === null) {
                $complete = false;

                continue;
            }

            foreach ($subjects as $subject) {
                $section = $this->fullSection($subject);

                if (mb_strlen($document) + mb_strlen($section) > $budget) {
                    $truncated = true;
                    break 2;
                }

                $document .= $section;
            }
        }

        if ($truncated) {
            $document .= "\n> Truncated: this site is larger than the configured llms-full.txt budget. "
                ."See /llms.txt for the complete index of pages.\n";
        }

        if ($complete) {
            Cache::put(self::FULL_CACHE_KEY, $document, $this->ttl());
        }

        return $document;
    }

    public function purge(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::FULL_CACHE_KEY);
    }

    private function header(SeoSettings $settings): string
    {
        $name = $settings->site_name !== '' ? $settings->site_name : (string) config('app.name', 'Site');
        $document = '# '.$this->escape($name)."\n";

        $summary = trim($settings->llms_txt_summary);

        if ($summary !== '') {
            // A blockquote directly under the H1 is where the format expects a
            // one-paragraph description of what the site is.
            $document .= "\n> ".$this->escape($summary)."\n";
        }

        return $document;
    }

    private function line(SeoSubject $subject): string
    {
        $line = '- ['.$this->escape($subject->title).']('.$subject->url.')';
        $description = $this->describe($subject);

        if ($description !== '') {
            $line .= ': '.$this->escape($description);
        }

        return $line."\n";
    }

    private function fullSection(SeoSubject $subject): string
    {
        $section = "\n## ".$this->escape($subject->title)."\n\n";
        $section .= 'Source: '.$subject->url."\n\n";

        $text = trim($subject->plainText);

        if ($text === '') {
            $text = $this->describe($subject);
        }

        return $section.$text."\n";
    }

    /**
     * A one-line description: the subject's excerpt, or a word-safe truncation of
     * its body. Kept short — this is a table of contents, not the content.
     */
    private function describe(SeoSubject $subject): string
    {
        $text = trim($subject->excerpt ?? '');

        if ($text === '') {
            $text = trim((string) preg_replace('/\s+/u', ' ', $subject->plainText));
        }

        if ($text === '' || mb_strlen($text) <= 160) {
            return $text;
        }

        $clipped = mb_substr($text, 0, 160);
        $lastSpace = mb_strrpos($clipped, ' ');

        return rtrim($lastSpace !== false && $lastSpace > 0 ? mb_substr($clipped, 0, $lastSpace) : $clipped).'…';
    }

    /**
     * The indexable subjects of one source, or null when the source failed — in
     * which case nothing is cached, so the next request retries instead of
     * publishing a map with a whole section silently missing.
     *
     * @return list<SeoSubject>|null
     */
    private function collect(string $handle, SeoSubjectSource $source): ?array
    {
        $subjects = [];
        $modelClass = $source->modelClass();

        try {
            $source->chunk(function (array $batch) use (&$subjects, $modelClass): void {
                $candidates = array_values(array_filter(
                    $batch,
                    static fn (SeoSubject $subject): bool => $subject->indexable && $subject->url !== '',
                ));

                foreach ($this->withoutNoindex($candidates, $modelClass) as $subject) {
                    $subjects[] = $subject;
                }
            });
        } catch (Throwable $e) {
            Log::warning('llms.txt: a source failed and was skipped.', [
                'source' => $handle,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        return $subjects;
    }

    /**
     * Drop pages an editor pushed out of the index. A page marked noindex is one
     * the site does not want represented by a machine, and that intent should not
     * depend on which machine is asking.
     *
     * @param  list<SeoSubject>  $subjects
     * @param  class-string|null  $modelClass
     * @return list<SeoSubject>
     */
    private function withoutNoindex(array $subjects, ?string $modelClass): array
    {
        if ($modelClass === null || $subjects === []) {
            return $subjects;
        }

        $pairs = [];
        foreach ($subjects as $subject) {
            if ($subject->modelId !== null && $subject->modelId !== '') {
                $pairs[] = [$modelClass, $subject->modelId];
            }
        }

        if ($pairs === []) {
            return $subjects;
        }

        $overrides = $this->meta->forMany($pairs);

        return array_values(array_filter($subjects, function (SeoSubject $subject) use ($overrides, $modelClass): bool {
            if ($subject->modelId === null || $subject->modelId === '') {
                return true;
            }

            $override = $overrides[$this->meta->key($modelClass, $subject->modelId)] ?? null;

            return $override === null || (bool) $override->getAttribute('robots_index') !== false;
        }));
    }

    /**
     * Markdown, not HTML: the characters that need neutralising are the ones that
     * would break a link or start a heading, and newlines that would end a list
     * item early.
     */
    private function escape(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return str_replace(['[', ']', '(', ')'], ['\[', '\]', '\(', '\)'], $value);
    }

    private function ttl(): int
    {
        $ttl = config('seo.llms.cache_ttl');

        return is_int($ttl) ? $ttl : 3600;
    }

    private function maxCharacters(): int
    {
        $max = config('seo.llms.full_max_characters');

        return is_int($max) && $max > 0 ? $max : 500000;
    }
}

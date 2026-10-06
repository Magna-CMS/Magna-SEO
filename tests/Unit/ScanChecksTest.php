<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Unit;

use DateTimeImmutable;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Scan\Checks\DuplicateDescriptionCheck;
use Magna\Seo\Scan\Checks\DuplicateTitleCheck;
use Magna\Seo\Scan\Checks\HreflangReciprocityCheck;
use Magna\Seo\Scan\Checks\MissingDescriptionCheck;
use Magna\Seo\Scan\Checks\MissingTitleCheck;
use Magna\Seo\Scan\Checks\ThinContentCheck;
use Magna\Seo\Scan\ScannedSubject;
use Magna\Seo\Subjects\SeoSubject;
use PHPUnit\Framework\TestCase;

final class ScanChecksTest extends TestCase
{
    private int $counter = 0;

    private function scanned(string $title = 'Title', ?string $excerpt = 'Excerpt', string $plainText = 'body text here'): ScannedSubject
    {
        $this->counter++;

        return new ScannedSubject('page', new SeoSubject(
            key: 'page:'.$this->counter,
            type: SubjectType::Page,
            url: 'https://example.test/'.$this->counter,
            title: $title,
            locale: 'en',
            indexable: true,
            updatedAt: new DateTimeImmutable('2026-08-15T00:00:00+00:00'),
            plainText: $plainText,
            excerpt: $excerpt,
        ));
    }

    public function test_missing_title_flags_only_empty_titles(): void
    {
        $issues = (new MissingTitleCheck)->run([$this->scanned(title: '   '), $this->scanned(title: 'Ok')]);

        $this->assertCount(1, $issues);
        $this->assertSame('missing-title', $issues[0]->check);
    }

    public function test_missing_description_needs_neither_excerpt_nor_body(): void
    {
        $issues = (new MissingDescriptionCheck)->run([
            $this->scanned(excerpt: null, plainText: ''),
            $this->scanned(excerpt: 'Has excerpt', plainText: ''),
            $this->scanned(excerpt: null, plainText: 'Has body'),
        ]);

        $this->assertCount(1, $issues);
        $this->assertSame('missing-description', $issues[0]->check);
    }

    public function test_thin_content_uses_the_word_threshold(): void
    {
        $check = new ThinContentCheck(minWords: 5);

        $issues = $check->run([
            $this->scanned(plainText: 'one two three'),
            $this->scanned(plainText: 'one two three four five six'),
        ]);

        $this->assertCount(1, $issues);
        $this->assertSame('thin-content', $issues[0]->check);
    }

    public function test_duplicate_title_flags_every_member_of_a_shared_group(): void
    {
        $issues = (new DuplicateTitleCheck)->run([
            $this->scanned(title: 'Same'),
            $this->scanned(title: 'same'),
            $this->scanned(title: 'Unique'),
        ]);

        $this->assertCount(2, $issues);
        foreach ($issues as $issue) {
            $this->assertSame('duplicate-title', $issue->check);
        }
    }

    /**
     * @param  array<string, string>  $alternates
     */
    private function withAlternates(string $url, array $alternates): ScannedSubject
    {
        $this->counter++;

        return new ScannedSubject('page', new SeoSubject(
            key: 'page:'.$this->counter,
            type: SubjectType::Page,
            url: $url,
            title: 'Title',
            locale: 'en',
            indexable: true,
            updatedAt: new DateTimeImmutable('2026-08-15T00:00:00+00:00'),
            alternates: $alternates,
        ));
    }

    public function test_hreflang_reciprocity_passes_for_a_mutual_pair(): void
    {
        $set = ['en' => 'https://x.test/a', 'fr' => 'https://x.test/b'];

        $issues = (new HreflangReciprocityCheck)->run([
            $this->withAlternates('https://x.test/a', $set),
            $this->withAlternates('https://x.test/b', $set),
        ]);

        $this->assertCount(0, $issues);
    }

    public function test_hreflang_reciprocity_flags_a_missing_backlink(): void
    {
        $issues = (new HreflangReciprocityCheck)->run([
            $this->withAlternates('https://x.test/a', ['en' => 'https://x.test/a', 'fr' => 'https://x.test/b']),
            $this->withAlternates('https://x.test/b', ['fr' => 'https://x.test/b']),
        ]);

        $this->assertCount(1, $issues);
        $this->assertSame('hreflang-reciprocity', $issues[0]->check);
    }

    public function test_duplicate_description_skips_subjects_without_an_excerpt(): void
    {
        $issues = (new DuplicateDescriptionCheck)->run([
            $this->scanned(excerpt: null),
            $this->scanned(excerpt: null),
            $this->scanned(excerpt: 'Shared'),
            $this->scanned(excerpt: 'Shared'),
        ]);

        $this->assertCount(2, $issues);
        $this->assertSame('duplicate-description', $issues[0]->check);
    }
}

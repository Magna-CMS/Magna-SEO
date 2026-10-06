<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Unit;

use Magna\Seo\Delivery\EntryPayloadMapper;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Url\EntryUrlResolver;
use PHPUnit\Framework\TestCase;

final class EntryPayloadMapperTest extends TestCase
{
    private function mapper(): EntryPayloadMapper
    {
        return new EntryPayloadMapper(
            titleFields: ['title', 'name'],
            excerptFields: ['excerpt', 'summary'],
            bodyFields: ['body'],
            imageFields: ['og_image', 'image'],
            defaultLocale: 'en',
            urls: new EntryUrlResolver('https://example.com', ['*' => '/{type}/{slug}']),
        );
    }

    public function test_it_returns_null_when_no_title_field_is_present(): void
    {
        $this->assertNull($this->mapper()->fromPayload('page', ['id' => '1', 'body' => 'x']));
    }

    public function test_it_maps_core_fields(): void
    {
        $subject = $this->mapper()->fromPayload('article', [
            'id' => '01H',
            'slug' => 'bonjour',
            'status' => 'published',
            'locale' => 'fr',
            'name' => 'Bonjour',
            'summary' => 'Résumé',
            'body' => '<p>Hello <b>world</b></p>',
            'published_at' => '2026-08-01T10:00:00+00:00',
            'updated_at' => '2026-08-14T12:00:00+00:00',
        ]);

        $this->assertNotNull($subject);
        $this->assertSame('entry:article:01H', $subject->key);
        $this->assertSame(SubjectType::Page, $subject->type);
        $this->assertSame('Bonjour', $subject->title);
        $this->assertSame('Résumé', $subject->excerpt);
        $this->assertSame('Hello world', $subject->plainText);
        $this->assertSame('fr', $subject->locale);
        $this->assertTrue($subject->indexable);
        $this->assertSame('2026-08-01', $subject->publishedAt?->format('Y-m-d'));
        $this->assertSame('https://example.com/article/bonjour', $subject->url);
    }

    public function test_draft_status_is_not_indexable_and_locale_falls_back(): void
    {
        $subject = $this->mapper()->fromPayload('page', [
            'id' => '1',
            'status' => 'draft',
            'title' => 'T',
        ]);

        $this->assertNotNull($subject);
        $this->assertFalse($subject->indexable);
        $this->assertSame('en', $subject->locale);
    }

    public function test_it_extracts_a_single_image_with_dimensions(): void
    {
        $subject = $this->mapper()->fromPayload('page', [
            'id' => '1',
            'title' => 'T',
            'og_image' => ['id' => 'm1', 'url' => 'https://cdn.test/a.jpg', 'width' => 1200, 'height' => 630, 'alt' => 'Alt'],
        ]);

        $image = $subject?->primaryImage();
        $this->assertNotNull($image);
        $this->assertSame('https://cdn.test/a.jpg', $image->url);
        $this->assertSame(1200, $image->width);
        $this->assertSame(630, $image->height);
        $this->assertSame('Alt', $image->alt);
    }

    public function test_it_extracts_the_first_image_from_a_media_list(): void
    {
        $subject = $this->mapper()->fromPayload('page', [
            'id' => '1',
            'title' => 'T',
            'image' => [
                ['id' => 'm1', 'url' => 'https://cdn.test/first.jpg'],
                ['id' => 'm2', 'url' => 'https://cdn.test/second.jpg'],
            ],
        ]);

        $this->assertSame('https://cdn.test/first.jpg', $subject?->primaryImage()?->url);
    }
}

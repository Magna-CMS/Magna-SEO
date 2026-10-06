<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Unit;

use DateTimeImmutable;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Subjects\SeoImage;
use Magna\Seo\Subjects\SeoSubject;
use PHPUnit\Framework\TestCase;

final class SeoSubjectTest extends TestCase
{
    private function subject(SeoImage ...$images): SeoSubject
    {
        return new SeoSubject(
            key: 'page:1',
            type: SubjectType::Page,
            url: 'https://example.test/1',
            title: 'Title',
            locale: 'en',
            indexable: true,
            updatedAt: new DateTimeImmutable('2026-08-14T00:00:00Z'),
            images: array_values($images),
        );
    }

    public function test_primary_image_is_the_first_image(): void
    {
        $first = new SeoImage('https://example.test/a.jpg');
        $second = new SeoImage('https://example.test/b.jpg');

        $this->assertSame($first, $this->subject($first, $second)->primaryImage());
    }

    public function test_primary_image_is_null_without_images(): void
    {
        $this->assertNull($this->subject()->primaryImage());
    }

    public function test_it_exposes_its_construction_values(): void
    {
        $subject = $this->subject();

        $this->assertSame('page:1', $subject->key);
        $this->assertSame(SubjectType::Page, $subject->type);
        $this->assertTrue($subject->indexable);
        $this->assertSame([], $subject->alternates);
        $this->assertNull($subject->author);
    }
}

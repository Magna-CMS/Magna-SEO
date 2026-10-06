<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Unit;

use DateTimeImmutable;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Meta\IndexabilityPolicy;
use Magna\Seo\Settings\SeoSettings;
use Magna\Seo\Subjects\SeoSubject;
use PHPUnit\Framework\TestCase;

final class IndexabilityPolicyTest extends TestCase
{
    private function subject(bool $indexable): SeoSubject
    {
        return new SeoSubject(
            key: 'page:1',
            type: SubjectType::Page,
            url: 'https://example.test/1',
            title: 'T',
            locale: 'en',
            indexable: $indexable,
            updatedAt: new DateTimeImmutable('2026-08-14T00:00:00+00:00'),
        );
    }

    public function test_indexable_subject_in_production_is_index_follow(): void
    {
        $policy = new IndexabilityPolicy(isProduction: true);

        $this->assertSame('index, follow', $policy->robotsContent($this->subject(true), null, new SeoSettings));
    }

    public function test_non_production_forces_noindex_by_default(): void
    {
        $policy = new IndexabilityPolicy(isProduction: false);

        $this->assertSame('noindex, follow', $policy->robotsContent($this->subject(true), null, new SeoSettings));
    }

    public function test_non_production_can_be_allowed_to_index(): void
    {
        $policy = new IndexabilityPolicy(isProduction: false);
        $settings = new SeoSettings;
        $settings->noindex_non_production = false;

        $this->assertSame('index, follow', $policy->robotsContent($this->subject(true), null, $settings));
    }

    public function test_a_non_indexable_subject_is_never_forced_into_the_index(): void
    {
        $policy = new IndexabilityPolicy(isProduction: true);

        $this->assertSame('noindex, follow', $policy->robotsContent($this->subject(false), null, new SeoSettings));
    }
}

<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Unit;

use DateTimeImmutable;
use InvalidArgumentException;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Registry\SeoSourceRegistry;
use Magna\Seo\Subjects\SeoSubject;
use Magna\Seo\Tests\Support\FakeSeoSubjectSource;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

final class SeoSourceRegistryTest extends TestCase
{
    private function subject(string $id): SeoSubject
    {
        return new SeoSubject(
            key: 'fake:'.$id,
            type: SubjectType::Page,
            url: 'https://example.test/'.$id,
            title: 'Title '.$id,
            locale: 'en',
            indexable: true,
            updatedAt: new DateTimeImmutable('2026-08-14T00:00:00Z'),
        );
    }

    public function test_it_registers_and_retrieves_a_source(): void
    {
        $registry = new SeoSourceRegistry(new NullLogger);
        $source = new FakeSeoSubjectSource('page');

        $registry->register($source);

        $this->assertTrue($registry->has('page'));
        $this->assertSame($source, $registry->get('page'));
        $this->assertNull($registry->get('missing'));
    }

    public function test_it_rejects_a_duplicate_handle(): void
    {
        $registry = new SeoSourceRegistry(new NullLogger);
        $registry->register(new FakeSeoSubjectSource('page'));

        $this->expectException(InvalidArgumentException::class);
        $registry->register(new FakeSeoSubjectSource('page'));
    }

    public function test_it_rejects_an_empty_handle(): void
    {
        $registry = new SeoSourceRegistry(new NullLogger);

        $this->expectException(InvalidArgumentException::class);
        $registry->register(new FakeSeoSubjectSource(''));
    }

    public function test_all_is_ordered_deterministically_by_handle(): void
    {
        $registry = new SeoSourceRegistry(new NullLogger);
        $registry->register(new FakeSeoSubjectSource('pages'));
        $registry->register(new FakeSeoSubjectSource('blog'));
        $registry->register(new FakeSeoSubjectSource('docs'));

        $this->assertSame(['blog', 'docs', 'pages'], array_keys($registry->all()));
    }

    public function test_resolve_returns_a_subject(): void
    {
        $registry = new SeoSourceRegistry(new NullLogger);
        $registry->register(new FakeSeoSubjectSource('page', ['1' => $this->subject('1')]));

        $subject = $registry->resolve('page', '1');

        $this->assertNotNull($subject);
        $this->assertSame('fake:1', $subject->key);
    }

    public function test_resolve_unknown_handle_returns_null(): void
    {
        $registry = new SeoSourceRegistry(new NullLogger);

        $this->assertNull($registry->resolve('nope', '1'));
    }

    public function test_resolve_swallows_and_logs_a_source_failure(): void
    {
        $logger = new class extends AbstractLogger
        {
            /** @var list<array{level: mixed, message: string}> */
            public array $records = [];

            /**
             * @param  array<mixed>  $context
             */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message];
            }
        };

        $registry = new SeoSourceRegistry($logger);
        $registry->register(new FakeSeoSubjectSource('page', [], throwOnResolve: true));

        $this->assertNull($registry->resolve('page', '1'));
        $this->assertContains('warning', array_column($logger->records, 'level'));
        $this->assertStringContainsString('SEO subject resolution failed.', $logger->records[0]['message']);
    }
}

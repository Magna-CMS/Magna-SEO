<?php

declare(strict_types=1);

namespace Magna\Seo\Enums;

use Magna\Seo\Subjects\SeoSubject;

/**
 * The kind of thing an {@see SeoSubject} represents. Drives
 * the default schema.org type and the default meta template chosen for it.
 */
enum SubjectType: string
{
    case Article = 'article';
    case Page = 'page';
    case Doc = 'doc';
    case Term = 'term';
    case Author = 'author';
    case Archive = 'archive';
    case Home = 'home';

    /**
     * The schema.org Article subtype this kind of content should be published as,
     * or null when it is not article-shaped at all. Being specific matters: rich
     * results treat a BlogPosting and a TechArticle differently, and emitting the
     * bare `Article` supertype for everything forfeits that.
     */
    public function articleSchemaType(): ?string
    {
        return match ($this) {
            self::Article => 'BlogPosting',
            self::Doc => 'TechArticle',
            default => null,
        };
    }
}

<?php

declare(strict_types=1);

namespace Magna\Seo\Head;

use Magna\Seo\Media\SocialImageResolver;
use Magna\Seo\Meta\MetaResolver;
use Magna\Seo\Models\SeoMeta;
use Magna\Seo\Schema\SchemaGraphBuilder;
use Magna\Seo\Settings\SeoSettings;
use Magna\Seo\Subjects\SeoSubject;

/**
 * The render-side entry point: turns a subject (and its optional per-entity
 * override) into a `<head>` fragment. This is the single API a rendering surface
 * — docs today, themed pages later — calls, so every rendered page gets the same
 * resolved meta and structured data as the delivery API, from one pipeline.
 *
 * Unlike the delivery decorator's list hot path, a render is one page, so it may
 * read the override and the site settings — a bounded, single-page cost.
 */
final class SeoHeadRenderer
{
    public function __construct(
        private readonly MetaResolver $resolver,
        private readonly SchemaGraphBuilder $schema,
        private readonly SocialImageResolver $socialImages,
        private readonly HeadHtmlRenderer $renderer,
    ) {}

    /**
     * Compose the full head payload (meta + links + JSON-LD graph) for a subject.
     */
    public function head(SeoSubject $subject, ?SeoMeta $override = null): HeadPayload
    {
        $settings = SeoSettings::get();

        // Dimensions are filled in here but not in delivery: a rendered page is
        // one page, and og:image:width/height are exactly what stop a social
        // preview reflowing as the image loads.
        $subject = $this->socialImages->augmentWithDimensions($subject, $override, $settings);
        $head = $this->resolver->resolve($subject, $override, $settings);

        $graph = $this->schema->build($subject, $head, $settings);
        if ($graph !== null) {
            $head = $head->withJsonLd([$graph]);
        }

        return $head;
    }

    /**
     * Render the escaped `<head>` fragment for a subject.
     */
    public function render(SeoSubject $subject, ?SeoMeta $override = null): string
    {
        return $this->renderer->render($this->head($subject, $override));
    }
}

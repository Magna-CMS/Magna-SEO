<?php

declare(strict_types=1);

namespace Magna\Seo\Delivery;

use Magna\Seo\Head\HeadHtmlRenderer;
use Magna\Seo\Media\SocialImageResolver;
use Magna\Seo\Meta\MetaResolver;
use Magna\Seo\Schema\SchemaGraphBuilder;
use Magna\Seo\Settings\SeoSettings;

/**
 * Injects a `seo` object into every entry's delivery payload. Bound as a scoped
 * singleton so the site settings are loaded once per request, not once per
 * entry: decorating a page of N entries therefore adds a single settings read,
 * never N. Per-entity overrides are intentionally not read here — a per-entry
 * DB lookup would reintroduce N+1 on list endpoints; they belong to the
 * single-item render path (and, later, a batched delivery hook).
 */
final class DeliverySeoDecorator
{
    private ?SeoSettings $settings = null;

    public function __construct(
        private readonly EntryPayloadMapper $mapper,
        private readonly MetaResolver $resolver,
        private readonly SchemaGraphBuilder $schema,
        private readonly SocialImageResolver $socialImages,
        private readonly HeadHtmlRenderer $renderer,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  modified in place
     */
    public function decorate(string $contentType, string $entryId, array &$payload): void
    {
        $subject = $this->mapper->fromPayload($contentType, $payload);
        if ($subject === null) {
            return;
        }

        $settings = $this->settings();
        $subject = $this->socialImages->augment($subject, null, $settings);
        $head = $this->resolver->resolve($subject, null, $settings);

        $graph = $this->schema->build($subject, $head, $settings);
        if ($graph !== null) {
            $head = $head->withJsonLd([$graph]);
        }

        $seo = $head->toArray();
        $seo['head_html'] = $this->renderer->render($head);

        $payload['seo'] = $seo;
    }

    private function settings(): SeoSettings
    {
        return $this->settings ??= SeoSettings::get();
    }
}

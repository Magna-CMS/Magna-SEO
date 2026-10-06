<?php

declare(strict_types=1);

namespace Magna\Seo\Media;

use Magna\Media\Media;
use Magna\Media\MediaUrlResolver;
use Magna\Seo\Models\SeoMeta;
use Magna\Seo\Settings\SeoSettings;
use Magna\Seo\Subjects\SeoImage;
use Magna\Seo\Subjects\SeoSubject;

/**
 * Resolves the social/preview image for a subject and folds it in, so og:image
 * and twitter:image always carry a real URL with width/height when one is
 * available anywhere. Fallback order: a per-entity override image, then the
 * subject's own image, then the site-default image.
 *
 * The site default is the same media for every page, so it is resolved once per
 * request (this resolver is bound scoped); an entry that already has its own
 * image, or a run with no default configured, does no media query at all —
 * keeping the delivery list path query-free.
 */
final class SocialImageResolver
{
    private ?SeoImage $siteDefault = null;

    private bool $siteDefaultResolved = false;

    public function __construct(
        private readonly MediaUrlResolver $urls,
        private readonly MediaDimensions $dimensions = new MediaDimensions,
    ) {}

    public function augment(SeoSubject $subject, ?SeoMeta $override, SeoSettings $settings): SeoSubject
    {
        $image = $this->fromId($override?->og_image_id)
            ?? $subject->primaryImage()
            ?? $this->siteDefault($settings);

        // A Twitter-specific override is the only reason to carry two images; when
        // absent, Twitter reuses the Open Graph one and no extra query is made.
        $twitter = $this->fromId($override?->twitter_image_id)?->forRole('twitter');

        if ($image === null && $twitter === null) {
            return $subject;
        }

        $images = array_values(array_filter([$image, $twitter]));

        return $subject->withImages($images);
    }

    /**
     * As {@see augment()}, but also fills in dimensions the source did not supply.
     *
     * Kept separate from augment() because that one is on the delivery hot path,
     * which is guaranteed query-free per entry. Render and audit paths handle a
     * bounded number of pages and can afford the lookup, which is memoised and
     * batched besides.
     */
    public function augmentWithDimensions(SeoSubject $subject, ?SeoMeta $override, SeoSettings $settings): SeoSubject
    {
        $subject = $this->augment($subject, $override, $settings);

        if ($subject->images === []) {
            return $subject;
        }

        return $subject->withImages(array_map(
            fn (SeoImage $image): SeoImage => $this->dimensions->enrich($image),
            $subject->images,
        ));
    }

    public function dimensions(): MediaDimensions
    {
        return $this->dimensions;
    }

    private function fromId(?string $id): ?SeoImage
    {
        $id = $id !== null ? trim($id) : '';
        if ($id === '') {
            return null;
        }

        $media = Media::query()->whereKey($id)->first();
        if (! $media instanceof Media) {
            return null;
        }

        return new SeoImage(
            url: $this->urls->publicUrl($media),
            id: $media->id,
            width: $media->width,
            height: $media->height,
            alt: $media->alt,
        );
    }

    private function siteDefault(SeoSettings $settings): ?SeoImage
    {
        if (! $this->siteDefaultResolved) {
            $this->siteDefault = $this->fromId($settings->default_og_image);
            $this->siteDefaultResolved = true;
        }

        return $this->siteDefault;
    }
}

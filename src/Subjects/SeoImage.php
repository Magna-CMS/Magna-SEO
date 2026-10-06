<?php

declare(strict_types=1);

namespace Magna\Seo\Subjects;

/**
 * An image associated with a subject, carrying the dimensions Open Graph and
 * structured data need. Width/height are what let the renderer emit
 * og:image:width / og:image:height and keep CLS near zero — supply them from
 * the media record whenever they are known.
 */
final readonly class SeoImage
{
    /**
     * @param  string|null  $role  Network this image is specific to, e.g. "twitter";
     *                             null means it is the general (Open Graph) image.
     */
    public function __construct(
        public string $url,
        public ?string $id = null,
        public ?int $width = null,
        public ?int $height = null,
        public ?string $alt = null,
        public ?string $role = null,
    ) {}

    /**
     * A copy tagged for one network, so a subject can carry a Twitter-specific
     * image alongside its Open Graph one without a second collection.
     */
    public function forRole(?string $role): self
    {
        return new self($this->url, $this->id, $this->width, $this->height, $this->alt, $role);
    }
}

<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use DateTimeImmutable;
use Magna\Media\Media;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Media\SocialImageResolver;
use Magna\Seo\Models\SeoMeta;
use Magna\Seo\Settings\SeoSettings;
use Magna\Seo\Subjects\SeoImage;
use Magna\Seo\Subjects\SeoSubject;
use Magna\Testing\PluginTestCase;

final class SocialImageResolverTest extends PluginTestCase
{
    protected string $plugin = 'magna-cms/seo';

    private function media(array $attributes = []): Media
    {
        return Media::create(array_merge([
            'disk' => 'public',
            'path' => 'social.jpg',
            'filename' => 'social.jpg',
            'original_filename' => 'social.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1000,
            'width' => 1200,
            'height' => 630,
            'alt' => 'Alt text',
        ], $attributes));
    }

    /**
     * @param  list<SeoImage>  $images
     */
    private function subject(array $images = []): SeoSubject
    {
        return new SeoSubject(
            key: 'page:1',
            type: SubjectType::Page,
            url: 'https://example.test/1',
            title: 'T',
            locale: 'en',
            indexable: true,
            updatedAt: new DateTimeImmutable('2026-08-15T00:00:00+00:00'),
            images: $images,
        );
    }

    private function resolver(): SocialImageResolver
    {
        return $this->app->make(SocialImageResolver::class);
    }

    public function test_site_default_is_used_when_the_subject_has_no_image(): void
    {
        $media = $this->media();
        $settings = new SeoSettings;
        $settings->default_og_image = $media->id;

        $image = $this->resolver()->augment($this->subject(), null, $settings)->primaryImage();

        $this->assertNotNull($image);
        $this->assertSame($media->id, $image->id);
        $this->assertSame(1200, $image->width);
        $this->assertSame('Alt text', $image->alt);
        $this->assertNotSame('', $image->url);
    }

    public function test_override_image_wins_over_subject_and_default(): void
    {
        $default = $this->media(['path' => 'default.jpg']);
        $override = $this->media(['path' => 'override.jpg']);

        $settings = new SeoSettings;
        $settings->default_og_image = $default->id;

        $subject = $this->subject([new SeoImage('https://example.test/subject.jpg')]);
        $meta = new SeoMeta(['og_image_id' => $override->id]);

        $image = $this->resolver()->augment($subject, $meta, $settings)->primaryImage();

        $this->assertSame($override->id, $image?->id);
    }

    public function test_subject_image_is_kept_when_there_is_no_override(): void
    {
        $settings = new SeoSettings;
        $settings->default_og_image = $this->media()->id;

        $subject = $this->subject([new SeoImage('https://example.test/subject.jpg')]);

        $image = $this->resolver()->augment($subject, null, $settings)->primaryImage();

        $this->assertSame('https://example.test/subject.jpg', $image?->url);
    }

    public function test_subject_is_unchanged_when_no_image_is_available_anywhere(): void
    {
        $out = $this->resolver()->augment($this->subject(), null, new SeoSettings);

        $this->assertNull($out->primaryImage());
    }
}

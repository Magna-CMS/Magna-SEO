<?php

declare(strict_types=1);

namespace Magna\Seo\Schema\Nodes;

use Magna\Seo\Contracts\SchemaNodeFactory;
use Magna\Seo\Schema\SchemaContext;

/**
 * A SoftwareApplication node, for a page that describes an app — the home page
 * of a product site, its download page.
 *
 * It is opt-in per page: the editor fills the "Software application" fields on
 * the page's SEO panel and they land in schema_overrides['software_application'].
 * That is editor input, so nothing is copied through. Every field is
 * whitelisted, typed and length-capped, URLs must be http(s) or root-relative,
 * and a value that fails is dropped rather than emitted.
 *
 * The @id is site-scoped and derived from the app's name, so the home page and
 * the download page describe ONE application that a consumer merges, not two.
 *
 * There is deliberately no aggregateRating or review here. Google only shows
 * software stars for real ratings, and a rating the site writes about itself
 * is exactly what its guidelines call spam — so the plugin cannot emit one.
 */
final class SoftwareApplicationNodeFactory implements SchemaNodeFactory
{
    /** The schema.org application categories Google recognises. */
    public const CATEGORIES = [
        'BusinessApplication', 'FinanceApplication', 'UtilitiesApplication',
        'ShoppingApplication', 'CommunicationApplication', 'DeveloperApplication',
        'DesignApplication', 'EducationalApplication', 'EntertainmentApplication',
        'GameApplication', 'HealthApplication', 'HomeApplication',
        'LifestyleApplication', 'MultimediaApplication', 'ReferenceApplication',
        'SecurityApplication', 'SocialNetworkingApplication', 'SportsApplication',
        'TravelApplication', 'BrowserApplication', 'DesktopEnhancementApplication',
        'DriverApplication',
    ];

    /** The schema_overrides key this factory reads. */
    public const KEY = 'software_application';

    public function make(SchemaContext $context): array
    {
        $data = $context->schemaOverrides[self::KEY] ?? null;
        if (! is_array($data)) {
            return [];
        }

        $name = $this->text($data['name'] ?? null, 120);
        if ($name === null) {
            return [];
        }

        $node = [
            '@type' => 'SoftwareApplication',
            '@id' => $context->baseUrl.'/#software-'.$this->slug($name),
            'name' => $name,
        ];

        if ($context->baseUrl !== '') {
            $node['url'] = $context->baseUrl;
        }

        $alternates = $this->texts($data['alternate_names'] ?? null, 5, 120);
        if ($alternates !== []) {
            $node['alternateName'] = count($alternates) === 1 ? $alternates[0] : $alternates;
        }

        $category = $data['application_category'] ?? null;
        if (is_string($category) && in_array($category, self::CATEGORIES, true)) {
            $node['applicationCategory'] = $category;
        }

        $subcategory = $this->text($data['application_subcategory'] ?? null, 120);
        if ($subcategory !== null) {
            $node['applicationSubCategory'] = $subcategory;
        }

        // schema.org types operatingSystem as Text; one comma-separated value is
        // the form Google's own examples use.
        $systems = $this->texts($data['operating_systems'] ?? null, 10, 60);
        if ($systems !== []) {
            $node['operatingSystem'] = implode(', ', $systems);
        }

        $version = $this->text($data['software_version'] ?? null, 40);
        if ($version !== null) {
            $node['softwareVersion'] = $version;
        }

        foreach (['download_url' => 'downloadUrl', 'install_url' => 'installUrl', 'screenshot' => 'screenshot'] as $field => $property) {
            $url = $this->url($data[$field] ?? null, $context->baseUrl);
            if ($url !== null) {
                $node[$property] = $url;
            }
        }

        $offers = $this->offers($data['offers'] ?? null, $context->baseUrl);
        if ($offers !== []) {
            $node['offers'] = $offers;
        }

        $languages = array_values(array_filter(
            $this->texts($data['in_language'] ?? null, 20, 35),
            static fn (string $tag): bool => preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $tag) === 1,
        ));
        if ($languages !== []) {
            $node['inLanguage'] = count($languages) === 1 ? $languages[0] : $languages;
        }

        $features = $this->texts($data['feature_list'] ?? null, 30, 200);
        if ($features !== []) {
            $node['featureList'] = $features;
        }

        if ($context->hasIdentity) {
            $node['publisher'] = ['@id' => $context->identityId];
        }

        return [$node];
    }

    /**
     * Each offer needs a non-negative decimal price and an ISO 4217 currency;
     * one without either is not an offer anyone can act on, so it is dropped.
     *
     * @return list<array<string, string>>
     */
    private function offers(mixed $offers, string $baseUrl): array
    {
        if (! is_array($offers)) {
            return [];
        }

        $clean = [];
        foreach (array_slice(array_values($offers), 0, 10) as $offer) {
            if (! is_array($offer)) {
                continue;
            }

            $price = is_int($offer['price'] ?? null) || is_float($offer['price'] ?? null)
                ? (string) $offer['price']
                : (is_string($offer['price'] ?? null) ? trim($offer['price']) : '');
            $currency = is_string($offer['currency'] ?? null) ? strtoupper(trim($offer['currency'])) : '';

            if (preg_match('/^\d{1,9}(\.\d{1,2})?$/', $price) !== 1 || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                continue;
            }

            $node = ['@type' => 'Offer', 'price' => $price, 'priceCurrency' => $currency];

            $name = $this->text($offer['name'] ?? null, 80);
            if ($name !== null) {
                $node['name'] = $name;
            }

            $url = $this->url($offer['url'] ?? null, $baseUrl);
            if ($url !== null) {
                $node['url'] = $url;
            }

            $clean[] = $node;
        }

        return $clean;
    }

    private function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /**
     * @return list<string>
     */
    private function texts(mixed $values, int $count, int $max): array
    {
        if (is_string($values)) {
            $values = explode(',', $values);
        }

        if (! is_array($values)) {
            return [];
        }

        $clean = [];
        foreach ($values as $value) {
            $text = $this->text($value, $max);
            if ($text !== null && ! in_array($text, $clean, true)) {
                $clean[] = $text;
            }
        }

        return array_slice($clean, 0, $count);
    }

    /**
     * An absolute http(s) URL, or a root-relative path made absolute against
     * the site. Anything else — javascript:, data:, protocol-relative — is
     * refused rather than emitted.
     */
    private function url(mixed $value, string $baseUrl): ?string
    {
        $value = $this->text($value, 2000);
        if ($value === null) {
            return null;
        }

        if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            return $baseUrl !== '' ? $baseUrl.$value : null;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $value : null;
    }

    private function slug(string $name): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');

        return $slug !== '' ? $slug : 'app';
    }
}

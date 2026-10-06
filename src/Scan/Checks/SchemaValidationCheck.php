<?php

declare(strict_types=1);

namespace Magna\Seo\Scan\Checks;

use Magna\Seo\Contracts\ScanCheck;
use Magna\Seo\Meta\MetaResolver;
use Magna\Seo\Scan\ScanIssue;
use Magna\Seo\Scan\Severity;
use Magna\Seo\Schema\SchemaGraphBuilder;
use Magna\Seo\Schema\SchemaValidator;
use Magna\Seo\Settings\SeoSettings;

/**
 * Structured data that would not survive a rich-results check: a node missing a
 * required property, two nodes sharing an @id, a reference to a node that is not
 * in the graph.
 *
 * Validation is local — no request to Google, no request to schema.org — so this
 * runs in CI and on a laptop with no network. The site settings are read once for
 * the whole scan.
 */
final class SchemaValidationCheck implements ScanCheck
{
    public function __construct(
        private readonly MetaResolver $resolver,
        private readonly SchemaGraphBuilder $schema,
        private readonly SchemaValidator $validator = new SchemaValidator,
    ) {}

    public function run(array $subjects): array
    {
        if ($subjects === []) {
            return [];
        }

        $settings = SeoSettings::get();
        $issues = [];

        foreach ($subjects as $scanned) {
            $head = $this->resolver->resolve($scanned->subject, null, $settings);
            $errors = $this->validator->validate($this->schema->build($scanned->subject, $head, $settings));

            if ($errors === []) {
                continue;
            }

            $issues[] = new ScanIssue(
                source: $scanned->source,
                subjectKey: $scanned->subject->key,
                url: $scanned->subject->url,
                check: 'schema-invalid',
                severity: Severity::Warning,
                message: count($errors).' structured-data problem(s): '.$errors[0],
            );
        }

        return $issues;
    }
}

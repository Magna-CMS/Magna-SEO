<?php

declare(strict_types=1);

namespace Magna\Seo\Analysis;

use Illuminate\Database\Eloquent\Model;

/**
 * Builds an {@see AnalysisInput} from a content record and runs the analysis.
 *
 * The analysis is computed when an editor saves, never when a page renders — it
 * is advice for the author, not part of the response — and the result is cached
 * on the SEO override row so reopening the editor costs nothing.
 *
 * Which columns hold the title, excerpt and body is configuration, exactly as it
 * is for delivery meta (see config/seo.php), because a content type has no fixed
 * schema.
 */
final class RecordAnalyser
{
    /**
     * @param  list<string>  $titleFields
     * @param  list<string>  $excerptFields
     * @param  list<string>  $bodyFields
     */
    public function __construct(
        private readonly ContentAnalyser $analyser,
        private readonly array $titleFields,
        private readonly array $excerptFields,
        private readonly array $bodyFields,
    ) {}

    public function analyse(Model $record, string $keyword, ?string $seoTitle, ?string $seoDescription): AnalysisReport
    {
        $html = $this->firstString($record, $this->bodyFields) ?? '';

        $input = new AnalysisInput(
            keyword: trim($keyword),
            // The override wins when set: it is what will actually be published.
            title: $seoTitle !== null && trim($seoTitle) !== ''
                ? trim($seoTitle)
                : ($this->firstString($record, $this->titleFields) ?? ''),
            description: $seoDescription !== null && trim($seoDescription) !== ''
                ? trim($seoDescription)
                : ($this->firstString($record, $this->excerptFields) ?? ''),
            slug: (string) ($record->getAttribute('slug') ?? ''),
            plainText: $this->plainText($html),
            html: $html,
        );

        return $this->analyser->analyse($input);
    }

    /**
     * @param  list<string>  $fields
     */
    private function firstString(Model $record, array $fields): ?string
    {
        foreach ($fields as $field) {
            $value = $record->getAttribute($field);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    private function plainText(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');
    }
}

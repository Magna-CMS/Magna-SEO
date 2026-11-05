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
     * @param  list<string>  $blockFields  Stored block documents, for records with no body field.
     */
    public function __construct(
        private readonly ContentAnalyser $analyser,
        private readonly array $titleFields,
        private readonly array $excerptFields,
        private readonly array $bodyFields,
        private readonly array $blockFields = [],
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
            plainText: $html !== '' ? $this->plainText($html) : $this->blockText($record),
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

    /**
     * A builder page keeps its text in a block document, not a body column.
     * Nothing goes to `html`: stored blocks are not the rendered markup, so the
     * structural checks say so rather than judging a page by a guess at it.
     */
    private function blockText(Model $record): string
    {
        foreach ($this->blockFields as $field) {
            $document = $record->getAttribute($field);
            $text = is_array($document) ? BlockText::from($document) : '';

            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private function plainText(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');
    }
}

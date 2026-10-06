<?php

declare(strict_types=1);

namespace Magna\Seo\Schema\Nodes;

use Magna\Seo\Contracts\SchemaNodeFactory;
use Magna\Seo\Schema\SchemaContext;

/**
 * A FAQPage node, built when a source exposes question/answer pairs under
 * SeoSubject::$raw['faq'] (e.g. a blog FAQ block). Eligible for FAQ rich results.
 * Each entry must have both a question and an answer or it is skipped.
 */
final class FaqNodeFactory implements SchemaNodeFactory
{
    public function make(SchemaContext $context): array
    {
        $faq = $context->subject->raw['faq'] ?? null;
        if (! is_array($faq) || $faq === []) {
            return [];
        }

        $questions = [];
        foreach ($faq as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $question = is_string($entry['question'] ?? null) ? trim($entry['question']) : '';
            $answer = is_string($entry['answer'] ?? null) ? trim($entry['answer']) : '';
            if ($question === '' || $answer === '') {
                continue;
            }

            $questions[] = [
                '@type' => 'Question',
                'name' => $question,
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer],
            ];
        }

        if ($questions === []) {
            return [];
        }

        $node = ['@type' => 'FAQPage', 'mainEntity' => $questions];
        if ($context->canonical !== null) {
            $node['@id'] = $context->canonical.'#faq';
        }

        return [$node];
    }
}

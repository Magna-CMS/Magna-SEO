<?php

declare(strict_types=1);

namespace Magna\Seo\Schema\Nodes;

use Magna\Seo\Contracts\SchemaNodeFactory;
use Magna\Seo\Schema\SchemaContext;

/**
 * A HowTo node, built when a source exposes ordered steps under
 * SeoSubject::$raw['howto'] (a list of { text, name? }). Eligible for HowTo rich
 * results. The subject title names the procedure.
 */
final class HowToNodeFactory implements SchemaNodeFactory
{
    public function make(SchemaContext $context): array
    {
        $howto = $context->subject->raw['howto'] ?? null;
        if (! is_array($howto) || $howto === []) {
            return [];
        }

        $steps = [];
        foreach ($howto as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $text = is_string($entry['text'] ?? null) ? trim($entry['text']) : '';
            if ($text === '') {
                continue;
            }

            $step = ['@type' => 'HowToStep', 'text' => $text];
            $name = is_string($entry['name'] ?? null) ? trim($entry['name']) : '';
            if ($name !== '') {
                $step['name'] = $name;
            }

            $steps[] = $step;
        }

        if ($steps === []) {
            return [];
        }

        $node = [
            '@type' => 'HowTo',
            'name' => $context->subject->title,
            'step' => $steps,
        ];
        if ($context->canonical !== null) {
            $node['@id'] = $context->canonical.'#howto';
        }

        return [$node];
    }
}

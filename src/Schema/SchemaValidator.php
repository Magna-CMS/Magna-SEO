<?php

declare(strict_types=1);

namespace Magna\Seo\Schema;

/**
 * Shape validation for a built `@graph`, performed locally with no network call.
 *
 * This is deliberately not a full schema.org vocabulary implementation. It checks
 * the things that actually break rich results in practice: a node with no type,
 * a required property missing, two nodes sharing an `@id`, an `@id` reference
 * pointing at a node that is not in the graph, and output that will not survive
 * json_encode. Those are the failures a site owner cannot see by eye and that the
 * Rich Results Test would otherwise be the first to report.
 */
final class SchemaValidator
{
    /**
     * Properties without which a node of a given type is not useful to a consumer.
     *
     * @var array<string, list<string>>
     */
    private const REQUIRED = [
        'WebSite' => ['url'],
        'WebPage' => ['name'],
        'Organization' => ['name'],
        'Person' => ['name'],
        'ImageObject' => ['url'],
        'Article' => ['headline'],
        'BlogPosting' => ['headline'],
        'NewsArticle' => ['headline'],
        'TechArticle' => ['headline'],
        'ScholarlyArticle' => ['headline'],
        'Report' => ['headline'],
        'BreadcrumbList' => ['itemListElement'],
        'FAQPage' => ['mainEntity'],
        'HowTo' => ['name', 'step'],
    ];

    /**
     * @param  array<string, mixed>|null  $graph  The document produced by SchemaGraphBuilder.
     * @return list<string> Human-readable problems; empty means valid.
     */
    public function validate(?array $graph): array
    {
        if ($graph === null) {
            return [];
        }

        $errors = [];

        if (($graph['@context'] ?? null) !== 'https://schema.org') {
            $errors[] = 'Structured data is missing the schema.org @context.';
        }

        $nodes = $graph['@graph'] ?? null;
        if (! is_array($nodes) || $nodes === []) {
            return [...$errors, 'Structured data contains no nodes.'];
        }

        $ids = [];

        foreach ($nodes as $index => $node) {
            if (! is_array($node)) {
                $errors[] = "Node {$index} is not an object.";

                continue;
            }

            $type = $node['@type'] ?? null;
            if (! is_string($type) || $type === '') {
                $errors[] = "Node {$index} has no @type.";

                continue;
            }

            $id = $node['@id'] ?? null;
            if (is_string($id) && $id !== '') {
                if (isset($ids[$id])) {
                    $errors[] = "Duplicate @id \"{$id}\" on more than one node.";
                }
                $ids[$id] = true;
            }

            foreach (self::REQUIRED[$type] ?? [] as $property) {
                $value = $node[$property] ?? null;
                if ($value === null || $value === '' || $value === []) {
                    $errors[] = "{$type} is missing required property \"{$property}\".";
                }
            }

            foreach ($this->typeSpecificErrors($type, $node) as $error) {
                $errors[] = $error;
            }
        }

        foreach ($this->danglingReferences($nodes, $ids) as $reference) {
            $errors[] = "Reference to @id \"{$reference}\" has no matching node in the graph.";
        }

        if (json_encode($graph) === false) {
            $errors[] = 'Structured data cannot be encoded as JSON: '.json_last_error_msg();
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @return list<string>
     */
    private function typeSpecificErrors(string $type, array $node): array
    {
        $errors = [];

        if ($type === 'BreadcrumbList') {
            $items = $node['itemListElement'] ?? [];
            foreach (is_array($items) ? $items : [] as $item) {
                if (! is_array($item) || ! isset($item['position'], $item['name'])) {
                    $errors[] = 'BreadcrumbList has an item without a position or name.';
                    break;
                }
            }
        }

        if ($type === 'FAQPage') {
            $questions = $node['mainEntity'] ?? [];
            foreach (is_array($questions) ? $questions : [] as $question) {
                $answer = is_array($question) ? ($question['acceptedAnswer'] ?? null) : null;
                $text = is_array($answer) ? ($answer['text'] ?? null) : null;

                if (! is_array($question) || ($question['name'] ?? '') === '' || ! is_string($text) || $text === '') {
                    $errors[] = 'FAQPage has a question without a name or an accepted answer.';
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * Every `{"@id": "…"}` pointer must resolve inside the same graph — a dangling
     * one silently drops the relationship it was meant to express.
     *
     * @param  array<array-key, mixed>  $nodes
     * @param  array<string, true>  $ids
     * @return list<string>
     */
    private function danglingReferences(array $nodes, array $ids): array
    {
        $dangling = [];

        $walk = function (mixed $value) use (&$walk, $ids, &$dangling): void {
            if (! is_array($value)) {
                return;
            }

            // A reference is an object whose only key is @id; a node that merely
            // carries an @id alongside its own data is a definition, not a pointer.
            if (array_keys($value) === ['@id'] && is_string($value['@id']) && ! isset($ids[$value['@id']])) {
                $dangling[] = $value['@id'];
            }

            foreach ($value as $child) {
                $walk($child);
            }
        };

        foreach ($nodes as $node) {
            foreach (is_array($node) ? $node : [] as $value) {
                $walk($value);
            }
        }

        return array_values(array_unique($dangling));
    }
}

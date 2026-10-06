<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Unit;

use Magna\Seo\Schema\SchemaValidator;
use PHPUnit\Framework\TestCase;

final class SchemaValidatorTest extends TestCase
{
    private function validator(): SchemaValidator
    {
        return new SchemaValidator;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return array<string, mixed>
     */
    private function graph(array $nodes): array
    {
        return ['@context' => 'https://schema.org', '@graph' => $nodes];
    }

    public function test_a_well_formed_graph_has_no_errors(): void
    {
        $graph = $this->graph([
            ['@type' => 'WebSite', '@id' => 'https://e.test/#website', 'url' => 'https://e.test'],
            ['@type' => 'WebPage', '@id' => 'https://e.test/p#webpage', 'name' => 'P', 'isPartOf' => ['@id' => 'https://e.test/#website']],
        ]);

        $this->assertSame([], $this->validator()->validate($graph));
    }

    public function test_a_null_graph_is_not_an_error(): void
    {
        $this->assertSame([], $this->validator()->validate(null));
    }

    public function test_it_flags_a_missing_type_and_a_missing_required_property(): void
    {
        $errors = $this->validator()->validate($this->graph([
            ['name' => 'no type'],
            ['@type' => 'BlogPosting'],
        ]));

        $this->assertContains('Node 0 has no @type.', $errors);
        $this->assertContains('BlogPosting is missing required property "headline".', $errors);
    }

    public function test_it_flags_duplicate_ids(): void
    {
        $errors = $this->validator()->validate($this->graph([
            ['@type' => 'WebPage', '@id' => 'x', 'name' => 'A'],
            ['@type' => 'WebPage', '@id' => 'x', 'name' => 'B'],
        ]));

        $this->assertContains('Duplicate @id "x" on more than one node.', $errors);
    }

    public function test_it_flags_a_reference_to_a_node_that_is_not_in_the_graph(): void
    {
        $errors = $this->validator()->validate($this->graph([
            ['@type' => 'WebPage', '@id' => 'p', 'name' => 'P', 'primaryImageOfPage' => ['@id' => 'missing']],
        ]));

        $this->assertContains('Reference to @id "missing" has no matching node in the graph.', $errors);
    }

    public function test_a_node_carrying_its_own_id_is_not_treated_as_a_dangling_reference(): void
    {
        $errors = $this->validator()->validate($this->graph([
            ['@type' => 'ImageObject', '@id' => 'https://e.test/p#img', 'url' => 'https://cdn/x.jpg'],
        ]));

        $this->assertSame([], $errors);
    }

    public function test_it_flags_an_faq_question_without_an_answer(): void
    {
        $errors = $this->validator()->validate($this->graph([
            ['@type' => 'FAQPage', 'mainEntity' => [['@type' => 'Question', 'name' => 'Q?']]],
        ]));

        $this->assertContains('FAQPage has a question without a name or an accepted answer.', $errors);
    }

    public function test_it_flags_an_empty_graph_and_a_wrong_context(): void
    {
        $errors = $this->validator()->validate(['@context' => 'http://schema.org', '@graph' => []]);

        $this->assertContains('Structured data is missing the schema.org @context.', $errors);
        $this->assertContains('Structured data contains no nodes.', $errors);
    }
}

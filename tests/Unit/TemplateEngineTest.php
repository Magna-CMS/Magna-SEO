<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Unit;

use Magna\Seo\Meta\TemplateEngine;
use PHPUnit\Framework\TestCase;

final class TemplateEngineTest extends TestCase
{
    public function test_it_replaces_known_tokens_case_insensitively(): void
    {
        $engine = new TemplateEngine;

        $out = $engine->render('%%title%% %%SEP%% %%sitename%%', [
            'title' => 'Hello',
            'sep' => '-',
            'sitename' => 'Acme',
        ]);

        $this->assertSame('Hello - Acme', $out);
    }

    public function test_unknown_tokens_collapse_and_whitespace_is_tidied(): void
    {
        $engine = new TemplateEngine;

        $out = $engine->render('%%title%%   %%missing%%  end', ['title' => 'X']);

        $this->assertSame('X end', $out);
    }

    public function test_a_template_without_tokens_is_returned_trimmed(): void
    {
        $engine = new TemplateEngine;

        $this->assertSame('Static title', $engine->render('  Static title  ', []));
    }
}

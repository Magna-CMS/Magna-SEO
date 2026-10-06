<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Magna\Testing\PluginTestCase;

/**
 * Every view in this plugin compiles to valid PHP, with no directive left
 * behind.
 *
 * Both halves matter, and the second is the one that bites. A Blade template can
 * compile to *syntactically valid* PHP while a chunk of it was never compiled at
 * all — the directives survive into the output as literal text and simply stop
 * working. That is what happened to the live editor panel: an inline `@php(...)`
 * appearing before a later `@php ... @endphp` block makes Blade pair the inline
 * opener with the block's `@endphp`, swallowing everything between as raw PHP.
 * The page rendered as a white screen and nothing in the toolchain objected.
 *
 * So this asserts two things per view: the compiled output parses, and no Blade
 * directive leaked through uncompiled. Cheap, and it covers the whole class of
 * failure rather than the one instance that was found by hand.
 */
final class ViewsCompileTest extends PluginTestCase
{
    /**
     * Directives that, appearing in compiled output, mean Blade stopped
     * compiling. `@endphp` is excluded — it legitimately never appears — and so
     * is anything inside an escaped `@@` literal, which is checked for.
     */
    private const DIRECTIVES = ['@if', '@endif', '@foreach', '@endforeach', '@php', '@forelse', '@include', '@can'];

    /**
     * @return list<string>
     */
    private function views(): array
    {
        $root = dirname(__DIR__, 2).'/resources/views';
        $found = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && str_ends_with($file->getFilename(), '.blade.php')) {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }

    public function test_the_plugin_ships_views_to_check(): void
    {
        // A silent zero here would make every assertion below vacuous.
        $this->assertGreaterThanOrEqual(6, count($this->views()));
    }

    public function test_every_view_compiles_to_valid_php(): void
    {
        foreach ($this->views() as $view) {
            $compiled = Blade::compileString((string) file_get_contents($view));

            $error = $this->syntaxErrorIn($compiled);

            $this->assertNull($error, basename($view).' does not compile: '.$error);
        }
    }

    public function test_no_view_leaves_a_directive_uncompiled(): void
    {
        foreach ($this->views() as $view) {
            $source = (string) file_get_contents($view);
            $compiled = Blade::compileString($source);

            // An escaped @@if is meant to survive as literal text.
            $escaped = substr_count($source, '@@');

            foreach (self::DIRECTIVES as $directive) {
                $leaked = substr_count($compiled, $directive);

                $this->assertLessThanOrEqual(
                    $escaped,
                    $leaked,
                    basename($view)." leaked an uncompiled {$directive}. Blade stopped compiling part of this "
                    .'template — commonly an inline @php(...) placed before a later @php ... @endphp block. '
                    .'Use the block form throughout.',
                );
            }
        }
    }

    public function test_no_alpine_attribute_contains_an_unescaped_quote(): void
    {
        // A double quote inside a double-quoted x-data ends the attribute, and
        // the rest of the component's JavaScript is then rendered as visible
        // text on the page. It compiles, it lints, and it looks like the view
        // exploded. It has happened once, in serp-preview, from a quoted word
        // inside a code comment.
        foreach ($this->views() as $view) {
            $source = (string) file_get_contents($view);

            preg_match_all('/\sx-data="/', $source, $opens, PREG_OFFSET_CAPTURE);

            foreach ($opens[0] as [$match, $offset]) {
                $start = $offset + strlen($match);
                $end = strpos($source, '"', $start);

                $this->assertNotFalse($end, basename($view).' has an unterminated x-data attribute.');

                // The attribute must run to the closing brace of its object
                // literal, so its braces balance. A stray quote ends it early,
                // mid-object, leaving more `{` than `}` — which is the signal,
                // because the truncated part always contains unclosed blocks.
                $body = substr($source, $start, $end - $start);

                $this->assertSame(
                    substr_count($body, '{'),
                    substr_count($body, '}'),
                    basename($view).' closes its x-data attribute before the component ends — there is an '
                    .'unescaped double quote inside it, and everything after it will render as page text. '
                    .'Use single quotes inside x-data.',
                );
            }
        }
    }

    /**
     * Lint compiled Blade.
     *
     * Done with `php -l` on a temporary file rather than eval(): compiled Blade
     * is PHP interleaved with HTML, so any in-process wrapper has to open and
     * close PHP mode around it and gets the bracket matching wrong on exactly
     * the templates that are mostly markup. The linter parses without executing,
     * which also means a view with side effects cannot fire during the check.
     */
    private function syntaxErrorIn(string $code): ?string
    {
        $file = tempnam(sys_get_temp_dir(), 'seo-view-').'.php';
        file_put_contents($file, $code);

        $output = (string) shell_exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1');

        @unlink($file);

        if (str_contains($output, 'No syntax errors detected')) {
            return null;
        }

        return trim(str_replace($file, 'the compiled view', $output));
    }
}

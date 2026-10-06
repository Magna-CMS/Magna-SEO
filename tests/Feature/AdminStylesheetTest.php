<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use Magna\Testing\PluginTestCase;

/**
 * The plugin ships its own stylesheet, and it has to keep working.
 *
 * A Tailwind build only emits the classes it can see, and a host application
 * scans its own source — not an installed plugin's views. On a distributed Magna
 * there is no npm and no way to re-run that build, so any class the host happens
 * not to use is absent and the view renders unstyled with no error anywhere.
 * Everything here guards a failure that already happened once.
 */
final class AdminStylesheetTest extends PluginTestCase
{
    private function css(): string
    {
        $path = __DIR__.'/../../resources/css/seo.css';

        $this->assertFileExists($path, 'The plugin stylesheet is part of the package, not a build artifact.');

        return (string) file_get_contents($path);
    }

    /**
     * @return list<string>
     */
    private function viewFiles(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__.'/../../resources/views'),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    public function test_the_stylesheet_stays_in_the_components_layer(): void
    {
        // This is the whole cascade decision, and getting it wrong is not subtle.
        // Unlayered, the sheet outranks every `dark:` variant the host ships and
        // paints dark text on dark panels. In `utilities` it wins ties against
        // the host for the same reason. `components` is opened by the host build
        // before `utilities`, so rules here apply only where nothing else does.
        $this->assertStringContainsString('@layer components {', $this->css());
        $this->assertStringNotContainsString('@layer utilities', $this->css());
    }

    public function test_the_stylesheet_is_injected_into_the_panel(): void
    {
        $plugin = (string) file_get_contents(__DIR__.'/../../src/SeoPlugin.php');

        $this->assertStringContainsString('registerAdminStyles', $plugin);

        // HEAD_START would create the layer before the host names it, and a layer
        // takes the position it is first named at — which pushed all of
        // Tailwind's utilities below its own reset and stripped the styling from
        // every page in the panel.
        $this->assertStringContainsString('PanelsRenderHook::HEAD_END', $plugin);
        $this->assertStringNotContainsString('PanelsRenderHook::HEAD_START', $plugin);
    }

    public function test_every_shipped_rule_is_still_used_by_a_view(): void
    {
        preg_match_all('/^\s{4}[.:][^{]*\{/m', $this->css(), $matches);

        $this->assertNotEmpty($matches[0], 'The stylesheet should define rules.');

        $markup = '';
        foreach ($this->viewFiles() as $file) {
            $markup .= file_get_contents($file);
        }

        foreach ($matches[0] as $selector) {
            // Recover the class name from the selector, un-escaping Tailwind's
            // colons, slashes and brackets.
            $backslash = chr(92);

            preg_match('/\.((?:[\w-]|'.$backslash.$backslash.'.)+)/', $selector, $class);

            if ($class === []) {
                continue;
            }

            $name = str_replace($backslash, '', $class[1]);

            $this->assertStringContainsString(
                $name,
                $markup,
                "The stylesheet still defines [{$name}], which no view uses any more.",
            );
        }
    }

    public function test_no_shipped_responsive_class_has_to_beat_a_host_utility(): void
    {
        // Rules here live in the `components` layer, below the host's
        // `utilities`, so a class shipped here can supply what the host lacks
        // but can never override what it provides. Pairing a shipped
        // `sm:w-64` with `w-full` therefore does nothing at any width — which
        // left the content list's search field spanning the whole row and
        // pushing its filter chips onto a second line.
        //
        // Anything that must win gets a plugin-owned class instead (see
        // .magna-seo-search), which has no host counterpart to lose to.
        $css = $this->css();

        preg_match_all('/\.(sm|md|lg|xl)\\\\:([a-z-]+?)-[\w.\\\\\[\]\/#]+\s*\{/', $css, $shipped, PREG_SET_ORDER);

        $properties = array_unique(array_map(static fn (array $m): string => $m[2], $shipped));

        foreach ($this->viewFiles() as $file) {
            preg_match_all('/class="([^"]*)"/', (string) file_get_contents($file), $attributes);

            foreach ($attributes[1] as $blob) {
                $classes = preg_split('/\s+/', trim($blob)) ?: [];

                foreach ($properties as $property) {
                    $responsive = array_filter(
                        $classes,
                        static fn (string $c): bool => preg_match('/^(sm|md|lg|xl):'.preg_quote($property, '/').'-/', $c) === 1,
                    );

                    if ($responsive === []) {
                        continue;
                    }

                    $base = array_filter(
                        $classes,
                        static fn (string $c): bool => preg_match('/^'.preg_quote($property, '/').'-/', $c) === 1,
                    );

                    $this->assertSame(
                        [],
                        array_values($base),
                        basename($file).' pairs '.implode(' ', $responsive).' with '.implode(' ', $base)
                        .'. The responsive class is shipped by this plugin and sits below the host\'s utilities, '
                        .'so it cannot override the unprefixed one and the layout will not change at any width. '
                        .'Use a plugin-owned class instead.',
                    );
                }
            }
        }
    }

    public function test_views_use_no_arbitrary_class_the_stylesheet_does_not_define(): void
    {
        // Arbitrary values (grid-cols-[1fr_2fr_auto] and friends) are the classes
        // a host build is least likely to have emitted by coincidence, so they
        // must be shipped explicitly or not used.
        $css = $this->css();

        foreach ($this->viewFiles() as $file) {
            $source = (string) file_get_contents($file);

            // Only look inside class attributes. Scanning the whole file matches
            // PHP array access in Blade expressions ($page['worst']) and reports
            // it as an undefined utility.
            preg_match_all('/class="([^"]*)"/', $source, $attributes);

            $tokens = preg_split('/\s+/', implode(' ', $attributes[1])) ?: [];

            $found = array_filter(
                $tokens,
                static fn (string $token): bool => preg_match('/^[a-z][\w:-]*-\[[^\]\s]+\]$/', $token) === 1,
            );

            foreach ($found as $class) {
                $backslash = chr(92);

                // Tailwind escapes every character CSS cannot take literally in
                // an identifier. Miss one and this reports a class that is in
                // fact defined.
                $specials = ['[', ']', ':', '#', '.', '/'];

                $escaped = str_replace(
                    $specials,
                    array_map(static fn (string $c): string => $backslash.$c, $specials),
                    $class,
                );

                $this->assertStringContainsString(
                    $escaped,
                    $css,
                    basename($file)." uses [{$class}], which no host build is likely to emit. Define it in resources/css/seo.css or use a plain utility.",
                );
            }
        }
    }
}

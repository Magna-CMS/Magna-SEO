<?php

declare(strict_types=1);

namespace Magna\Seo\Meta;

/**
 * Expands %%token%% placeholders in a meta template. Deliberately trivial and
 * value-agnostic: it knows nothing about which tokens exist, so callers own the
 * token vocabulary. Unknown tokens collapse to an empty string; the result is
 * whitespace-tidied so a template that references an empty token does not leave
 * a double space behind.
 */
final class TemplateEngine
{
    /**
     * @param  array<string, string>  $tokens  Keyed by token name without the %% delimiters (case-insensitive).
     */
    public function render(string $template, array $tokens): string
    {
        $lookup = [];
        foreach ($tokens as $name => $value) {
            $lookup[strtolower($name)] = $value;
        }

        $expanded = preg_replace_callback(
            '/%%([a-z0-9_]+)%%/i',
            static fn (array $m): string => $lookup[strtolower($m[1])] ?? '',
            $template,
        ) ?? '';

        $collapsed = preg_replace('/\s+/u', ' ', $expanded) ?? $expanded;

        return trim($collapsed);
    }
}

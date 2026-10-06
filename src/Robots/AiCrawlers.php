<?php

declare(strict_types=1);

namespace Magna\Seo\Robots;

/**
 * The AI crawler user-agents a site owner actually has a decision to make about,
 * and what each one does — because "block AI bots" is not one decision but
 * several, and conflating them is how sites accidentally remove themselves from
 * ChatGPT's citations while still being used for training.
 *
 * Three distinct purposes:
 *
 * - **training** — collects pages to train a model. Blocking costs nothing in
 *   traffic and is the one most owners mean.
 * - **search** — builds the index an assistant cites from. Blocking this removes
 *   the site from AI answers, which is usually the opposite of what is wanted.
 * - **user** — fetches a page live because a person asked about that URL.
 *   Blocking breaks the experience for someone who deliberately linked you.
 */
final class AiCrawlers
{
    public const TRAINING = 'training';

    public const SEARCH = 'search';

    public const USER = 'user';

    /**
     * @return array<string, array{purpose: string, operator: string}>
     */
    public static function all(): array
    {
        return [
            'GPTBot' => ['purpose' => self::TRAINING, 'operator' => 'OpenAI'],
            'OAI-SearchBot' => ['purpose' => self::SEARCH, 'operator' => 'OpenAI'],
            'ChatGPT-User' => ['purpose' => self::USER, 'operator' => 'OpenAI'],
            'ClaudeBot' => ['purpose' => self::TRAINING, 'operator' => 'Anthropic'],
            'Claude-SearchBot' => ['purpose' => self::SEARCH, 'operator' => 'Anthropic'],
            'Claude-User' => ['purpose' => self::USER, 'operator' => 'Anthropic'],
            'Google-Extended' => ['purpose' => self::TRAINING, 'operator' => 'Google'],
            'PerplexityBot' => ['purpose' => self::SEARCH, 'operator' => 'Perplexity'],
            'Perplexity-User' => ['purpose' => self::USER, 'operator' => 'Perplexity'],
            'Applebot-Extended' => ['purpose' => self::TRAINING, 'operator' => 'Apple'],
            'meta-externalagent' => ['purpose' => self::TRAINING, 'operator' => 'Meta'],
            'Bytespider' => ['purpose' => self::TRAINING, 'operator' => 'ByteDance'],
            'CCBot' => ['purpose' => self::TRAINING, 'operator' => 'Common Crawl'],
        ];
    }

    /**
     * The user-agents serving one purpose.
     *
     * @return list<string>
     */
    public static function forPurpose(string $purpose): array
    {
        $agents = [];

        foreach (self::all() as $agent => $meta) {
            if ($meta['purpose'] === $purpose) {
                $agents[] = $agent;
            }
        }

        return $agents;
    }
}

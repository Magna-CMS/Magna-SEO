<?php

declare(strict_types=1);

namespace Magna\Seo\Listeners;

use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Magna\Content\Entry;
use Magna\Seo\Support\SeoMetaRepository;
use Magna\Users\User;
use Throwable;

/**
 * Tells an author, at the moment they publish, when the page they just made
 * live has an obvious SEO problem.
 *
 * **It warns; it never blocks.** An editor publishing a two-line announcement
 * knows it is a two-line announcement, and a tool that refuses to publish it is
 * a tool people route around. The warning exists for the other case: the person
 * who genuinely forgot the description on the article they spent a day writing.
 *
 * It reads the analysis cached when the entry was saved rather than recomputing,
 * so publishing stays as fast as it was.
 */
final class WarnOnWeakPublish
{
    /** Below this, the page needs work by the usual convention. */
    private const WEAK_SCORE = 41;

    public function __construct(private readonly SeoMetaRepository $repository) {}

    public function handle(object $event): void
    {
        try {
            $entry = $event->entry ?? null;
            $actorId = $event->actorId ?? null;

            if (! $entry instanceof Entry || ! is_string($actorId)) {
                return;
            }

            $problems = $this->problemsFor($entry);

            if ($problems === []) {
                return;
            }

            $user = User::query()->whereKey($actorId)->first();

            if (! $user instanceof User) {
                return;
            }

            Notification::make()
                ->title('Published — but this page has SEO issues')
                ->body(implode(' ', $problems).' You can fix these at any time; the page is live either way.')
                ->warning()
                ->sendToDatabase($user);
        } catch (Throwable $e) {
            // Publishing must never fail because a warning could not be raised.
            Log::warning('SEO publish warning skipped.', ['exception' => $e->getMessage()]);
        }
    }

    /**
     * The few problems worth interrupting a publish for: no description at all,
     * and a genuinely weak analysis score. Everything else waits for the scan.
     *
     * @return list<string>
     */
    private function problemsFor(Entry $entry): array
    {
        $meta = $this->repository->forModel($entry);
        $problems = [];

        if ($meta === null || trim((string) $meta->description) === '') {
            $problems[] = 'It has no meta description, so search engines will invent one from the page text.';
        }

        $cache = $meta?->analysis_cache;
        $score = is_array($cache) ? ($cache['score'] ?? null) : null;

        if (is_int($score) && $score < self::WEAK_SCORE) {
            $problems[] = "Its content analysis scores {$score}/100 — open the SEO panel to see which checks are failing.";
        }

        return $problems;
    }
}

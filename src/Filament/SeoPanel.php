<?php

declare(strict_types=1);

namespace Magna\Seo\Filament;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Magna\Seo\Analysis\AnalysisCategory;
use Magna\Seo\Analysis\AnalysisInput;
use Magna\Seo\Analysis\AnalysisReport;
use Magna\Seo\Analysis\ContentAnalyser;
use Magna\Seo\Analysis\ProminentWords;

/**
 * The live SEO panel: two traffic-light scores that move while an author types.
 *
 * The checks are not reimplemented in JavaScript. The same pure PHP
 * {@see ContentAnalyser} that runs on save runs here, over a debounced Livewire
 * round trip — one implementation, so the score in the editor can never disagree
 * with the score in the scan. The analyser is a handful of string passes and one
 * DOM parse; the round trip costs more than the analysis does.
 *
 * State arrives from the surrounding form rather than being owned here: the
 * panel is a lens on the draft, not another place to edit it.
 */
class SeoPanel extends Component
{
    public string $title = '';

    public string $description = '';

    public string $slug = '';

    public string $body = '';

    public string $keyword = '';

    public string $url = '';

    /**
     * Recompute when the form tells us something changed. Debouncing lives in
     * the blade binding, so a fast typist causes one analysis, not thirty.
     *
     * @param  array<string, mixed>  $state
     */
    public function syncFromForm(array $state): void
    {
        $this->title = $this->str($state, 'title');
        $this->description = $this->str($state, 'description');
        $this->slug = $this->str($state, 'slug');
        $this->body = $this->str($state, 'body');
        $this->keyword = $this->str($state, 'keyword');

        unset($this->report);
    }

    /**
     * Whether there is anything worth scoring yet.
     *
     * A blank draft used to show 38/100 and 50/100 — numbers assembled almost
     * entirely from checks that cannot fail on an empty page, presented with the
     * same confidence as a real score. An author opening a new post was told
     * their unwritten article was failing.
     *
     * Body text is the test rather than the title: every content, keyword,
     * link, heading and readability check reads the body, so a title on its own
     * scores nothing but absence.
     */
    public function hasContent(): bool
    {
        return $this->plainText() !== '';
    }

    #[Computed(persist: false)]
    public function report(): AnalysisReport
    {
        return app(ContentAnalyser::class)->analyse(new AnalysisInput(
            keyword: trim($this->keyword),
            title: $this->title,
            description: $this->description,
            slug: $this->slug,
            plainText: $this->plainText(),
            html: $this->body,
            siteUrl: $this->url,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function categories(): array
    {
        $report = $this->report();

        $out = [];
        foreach ([AnalysisCategory::Seo, AnalysisCategory::Readability] as $category) {
            $score = $report->scoreFor($category);

            $out[$category->value] = [
                'label' => $category->label(),
                'score' => $score,
                'band' => AnalysisReport::band($score)->value,
                'bandLabel' => AnalysisReport::bandLabel($score),
                'results' => $report->resultsFor($category),
            ];
        }

        return $out;
    }

    /**
     * What the draft is actually about, next to what its author said it is
     * about. The mismatch between those two is the single most common reason a
     * page does not rank for the term it was written for.
     *
     * @return list<array{word: string, count: int, isKeyword: bool}>
     */
    public function prominentWords(): array
    {
        $keyword = mb_strtolower(trim($this->keyword));

        return array_map(
            static fn (array $row): array => [
                ...$row,
                'isKeyword' => $keyword !== '' && str_contains($keyword, $row['word']),
            ],
            ProminentWords::of($this->title.' '.$this->plainText(), 8),
        );
    }

    public function render(): mixed
    {
        return view('seo::filament.seo-panel');
    }

    private function plainText(): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', strip_tags($this->body)));
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function str(array $state, string $key): string
    {
        $value = $state[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}

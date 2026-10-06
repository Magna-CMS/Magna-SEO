{{--
    Findings in detail.

    Kept as its own page because notifications and the dashboard's "start here"
    card deep-link straight to it, but no longer in the navigation — everything
    it shows is on the dashboard, and two sidebar entries for one subject is one
    too many.
--}}
<x-filament-panels::page>
    @php
        $data = $this->summaryData();
        $coverage = $this->keywordCoverage();
    @endphp

    @include('seo::filament.partials.findings', ['data' => $data])

    @include('seo::filament.partials.keyword-coverage', ['coverage' => $coverage])
</x-filament-panels::page>

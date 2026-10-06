# The admin screens

Seven screens under **SEO** in the panel. What each is for, and — more usefully —
which question each one answers.

## Dashboard

*"How is the site doing, and what should I do next?"*

Four figures across the top: site health with its movement since the last scan,
organic clicks, average position, and open critical issues. Each states its
window; a number with no period attached cannot be compared to anything.

Below that, a **Start here** card naming the single most common problem and its
remedy. On most sites an hour spent on the top finding removes more issues than
an hour spread across the list.

**Almost on page one** lists queries ranking 11–20. This is the most actionable
list in the plugin: those pages already rank, and a modest improvement moves them
somewhere people actually look. New content takes months; these take an edit.

The tiles that need Search Console show a connect prompt when it is not set up,
never zeroes — "no clicks" and "we cannot see your clicks" are different facts.

## Content

*"Show me everything with no meta description."*

Every indexable page across every content source, with its SEO and readability
scores, sorted worst-first because the list is a work queue rather than something
to browse.

Filters: needs work, no meta description, no focus keyword, marked noindex.
Title and description are editable in place — fixing two hundred missing
descriptions by opening two hundred editors is not a workflow anybody finishes.

Scores come from the analysis cached when each page was last saved. A page that
has never been analysed shows a dash, not a zero: zero reads as "scored badly"
where the truth is "not measured".

## Health

*"What exactly is wrong, and how do I fix it?"*

Every finding from the last scan, each with **How to fix this** — two or three
sentences of concrete instruction rather than a restatement of the problem.

Also carries keyword coverage: pages nobody set a keyword on, and pages competing
with each other for the same one. Two pages targeting one keyword split whatever
authority the topic earned and let a search engine pick between them more or less
arbitrarily.

## Redirects and Not found (404)

*"Where are visitors hitting dead ends?"*

The 404 log counts distinct paths and sorts by hits, because fixing the top few
recovers most of the lost traffic. Each row has a one-click **Redirect this**, so
going from evidence to fix never involves retyping a path.

The redirect form is opinionated about the three mistakes that cost most: a 302
where a 301 was meant, a destination that is not a URL, and a regex that does not
compile — the last is checked when you save, so a broken pattern is a form error
rather than a rule that silently never fires.

## Settings

Site identity, templates, social defaults, verification tokens, AI crawler
policy, and the Search Console, Bing and CrUX credentials. Secrets are encrypted
at rest and never appear in a request body.

The **AI platforms** section is worth reading rather than skimming: the three
crawler switches are what actually decide whether AI platforms may use the site,
and blocking AI *search* removes you from assistant answers while blocking
*training* costs no traffic at all. llms.txt, in the same section, does not affect
indexing either way — see [AI platforms](ai-platforms.md).

## Setup

A checklist, not a wizard: every step reports whether it is already done, so
arriving halfway through a migration shows what is left rather than walking you
through decisions already made. It disappears from the navigation once dismissed.

## In the entry editor

The SEO panel on every content type carries two scores — SEO and readability,
separate because they are fixed by completely different edits — updating as you
type. Below them: what the page is *actually* about (its most frequent
meaningful words, against the focus keyword you declared), the failing checks
sorted to the top, a Google preview truncated by real pixel width, and Facebook
and X previews, which crop differently enough that one preview would be wrong for
at least one of them.

The entry list itself carries the same two scores as badges, with filters for
needs-work, missing description and noindex.

## What none of this promises

No plugin decides rankings. Content quality, topical authority and backlinks do,
against whoever else wants that query. What this one promises is narrower and
checkable: every technical blocker it can detect is either fixed or listed on the
dashboard, and `seo:scorecard` will fail your build if a release breaks one of
the ten invariants.

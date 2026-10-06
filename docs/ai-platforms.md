# AI platforms: llms.txt and crawler control

Two separate mechanisms get confused with each other constantly, so start here.

**`robots.txt` decides whether an AI platform may use your site.** It is the
mechanism with teeth. Every major AI crawler reads it, and blocking one there is
what actually stops the site being crawled for training or cited in an assistant's
answer.

**`llms.txt` does not.** It is a convention proposed in 2024 and adopted mainly by
documentation platforms. No search or AI provider treats it as a signal for
whether to index a site. It is read at inference time by a model or agent already
pointed at your site, and it exists because a model handed raw HTML burns most of
its context on navigation and markup. Publishing it will not get you into
ChatGPT; blocking `GPTBot` will keep you out.

Both are worth having. They just answer different questions.

## Crawler control

Three toggles in **SEO settings → AI platforms**, because "block AI bots" is not
one decision:

| Setting | Agents | What you lose by blocking |
| --- | --- | --- |
| Allow AI search crawlers | `OAI-SearchBot`, `Claude-SearchBot`, `PerplexityBot` | Your site stops appearing in AI-assistant answers. Usually the opposite of what people want. |
| Allow crawling for model training | `GPTBot`, `ClaudeBot`, `Google-Extended`, `Applebot-Extended`, `meta-externalagent`, `Bytespider`, `CCBot` | Nothing in traffic terms. This is the one most owners actually mean. |
| Allow live fetches on a user request | `ChatGPT-User`, `Claude-User`, `Perplexity-User` | Someone who deliberately pasted your URL gets an error instead of your page. |

All three default to allowed. Blocked agents get an explicit `Disallow: /` block in
the generated `robots.txt`; allowed ones get nothing, because the wildcard already
permits them and restating it per agent is noise.

Outside production nothing is emitted per agent — everything is already
disallowed.

> A static `public/robots.txt` is served by your web server *before* PHP runs and
> shadows all of this. Run `php artisan seo:robots` to move it aside; the site
> scan and the scorecard both flag it if you forget.

## llms.txt

`/llms.txt` is generated from the same content set as your sitemaps, so the two
can never describe different sites:

```markdown
# Acme Docs

> Everything about the Acme platform.

## Documentation

- [Introduction](https://acme.test/docs/intro): What Acme is and who it is for.
- [Setup](https://acme.test/docs/setup): Install, configure and run your first job.
```

An H1 with the site name, an optional blockquote summary you write in settings,
then one `##` section per registered content source. Unpublished pages and any
page an editor marked `noindex` are excluded — that intent should not depend on
which machine is asking.

`/llms-full.txt` is the same map with each page's text inlined, for a model that
would otherwise fetch every URL. It is off by default and capped by
`config('seo.llms.full_max_characters')` (500 000): the point of the file is to
fit in a context window, so a site past the cap is truncated with a note pointing
back at `/llms.txt` rather than silently cut off.

Both are cached and purged on the same events as the sitemaps, so a newly
published page appears immediately.

When `/llms.txt` is published, `robots.txt` advertises it as a comment:

```
# llms.txt: https://acme.test/llms.txt
```

A comment, not a directive — the key is not part of the robots.txt specification,
and a parser that does not know it must not treat the line as a rule.

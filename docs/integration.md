# Integrating a content plugin with Magna SEO

SEO knows nothing about your models. It knows about **subjects**: immutable
descriptions of one indexable thing. You describe your content once, and meta,
Open Graph, structured data, sitemaps, hreflang, scans and content analysis all
follow from it.

## The whole contract

Implement `Magna\Seo\Contracts\SeoSubjectSource`:

```php
interface SeoSubjectSource
{
    public function handle(): string;                 // 'articles' — also the sitemap file name
    public function label(): string;                  // 'Articles' — shown in admin surfaces
    public function resolve(string $id): ?SeoSubject; // one subject, or null
    public function chunk(callable $callback, int $size = 500): void; // stream all indexable subjects
    public function modelClass(): ?string;            // the Eloquent model overrides attach to
}
```

`handle()` must be lowercase and dash-separated, and must not end in a number —
it becomes a sitemap file name, where a trailing `-2` means "page 2".

`chunk()` must actually chunk. It is used to build sitemaps and run scans, and a
source that loads its whole table into memory will take the site down on a large
install, not merely be slow.

## Registering without depending on SEO

Register from your plugin's `boot()`, guarded, so your plugin works whether or
not SEO is installed:

```php
if (class_exists(SeoSourceRegistry::class)) {
    $this->app->afterResolving(
        SeoSourceRegistry::class,
        fn (SeoSourceRegistry $registry) => $registry->has('articles')
            ?: $registry->register(new ArticleSubjectSource),
    );
}
```

`afterResolving` makes registration order-independent, and the `has()` check makes
it idempotent — both matter because plugins boot in an order you do not control.

## Building a subject

```php
new SeoSubject(
    key: 'article:'.$article->id,   // stable, source-scoped identity
    type: SubjectType::Article,     // drives og:type and the schema.org subtype
    url: $this->urlFor($article),   // absolute; '' means "not publicly renderable"
    title: $article->title,
    locale: $article->locale,
    indexable: $article->isPublished(),
    updatedAt: $article->updated_at->toDateTimeImmutable(),
    plainText: strip_tags($article->body),
    excerpt: $article->excerpt,
    publishedAt: $article->published_at?->toDateTimeImmutable(),
    alternates: ['fr' => 'https://example.com/fr/…'],  // locale => URL, for hreflang
    images: [new SeoImage(url: $url, width: 1200, height: 630, alt: 'Alt')],
    breadcrumbs: [new SeoCrumb('Home', 'https://example.com/'), new SeoCrumb('Article')],
    author: new SeoAuthor('Jane Doe', url: 'https://example.com/authors/jane'),
    raw: ['html' => $article->body],  // opt-in extras; see below
    modelId: (string) $article->id,   // lets collection callers bulk-load overrides
);
```

Two fields are worth dwelling on.

**`url`.** An empty URL is not a missing value, it is a statement: this content
is not publicly renderable. Such subjects get no canonical, are excluded from
sitemaps, and resolve to `noindex`. Never invent a URL to fill the field.

**`modelId`.** Without it, collection callers cannot pair a subject with its
per-entity override, so a page an editor marked `noindex` would still appear in
your sitemap. With it, overrides are bulk-loaded one query per chunk.

### Optional `raw` keys

| Key | Effect |
| --- | --- |
| `html` | Body markup. Enables the structural analysis checks (headings, links, alt text) and the orphan-page scan. |
| `schema_type` | A more precise Article subtype: `NewsArticle`, `TechArticle`, … |
| `faq` | `[['question' => …, 'answer' => …], …]` — emits a `FAQPage` node. |
| `howto` | `['name' => …, 'steps' => [...]]` — emits a `HowTo` node. |

## Rendering

A plugin that renders HTML calls the renderer and drops the fragment into its
layout:

```php
$head = app(SeoHeadRenderer::class)->render($subject, $override);
```

Guard the call with `app()->bound(SeoSourceRegistry::class)` and keep a fallback
`<head>` for installs without SEO. The docs plugin
(`plugins-dev/magna-cms/docs/src/Seo/DocsSubjectSource.php`) is a worked example
of the whole path.

Content entries need no work at all: their meta is derived from the delivery
payload automatically.

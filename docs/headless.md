# Headless usage

A headless frontend renders its own `<head>` and its own 404 page, so it needs
two things from the CMS: the resolved meta for a page, and an answer to "does
this dead URL redirect anywhere?".

## Meta

Every entry returned by the delivery API carries a `seo` object, added with zero
extra queries per entry:

```json
{
  "seo": {
    "title": "Espresso basics — Acme",
    "meta": { "description": "…", "robots": "index, follow", "twitter:card": "summary_large_image" },
    "properties": { "og:title": "…", "og:type": "article", "og:image": "https://…" },
    "links": { "canonical": "https://example.com/blog/espresso-basics" },
    "alternates": [ { "hreflang": "en", "href": "https://…" }, { "hreflang": "x-default", "href": "https://…" } ],
    "jsonld": [ { "@context": "https://schema.org", "@graph": [ … ] } ],
    "head_html": "<title>…</title>…"
  }
}
```

Drive your framework's head primitives from the structured fields, or inject
`head_html` as-is. Both are the same data; the structured form is there so you
can merge it with your own tags rather than concatenating strings.

### Next.js

```tsx
export async function generateMetadata({ params }) {
  const entry = await getEntry(params.slug)
  const seo = entry.seo

  return {
    title: seo.title,
    description: seo.meta.description,
    robots: seo.meta.robots,
    alternates: {
      canonical: seo.links.canonical,
      languages: Object.fromEntries(seo.alternates.map(a => [a.hreflang, a.href])),
    },
    openGraph: { title: seo.properties['og:title'], images: [seo.properties['og:image']] },
  }
}
```

Render the JSON-LD as its own script tag:

```tsx
<script type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: JSON.stringify(entry.seo.jsonld[0]) }} />
```

### Nuxt

```ts
const { seo } = entry
useHead({
  title: seo.title,
  meta: Object.entries(seo.meta).map(([name, content]) => ({ name, content })),
  link: [{ rel: 'canonical', href: seo.links.canonical }],
  script: [{ type: 'application/ld+json', innerHTML: JSON.stringify(seo.jsonld[0]) }],
})
```

### Astro

`Astro.props.entry.seo.head_html` can be emitted directly with `set:html` inside
`<head>`, which is the shortest correct path when you have no head-merging
requirements of your own.

## URLs

SEO builds canonical URLs from path patterns you configure per content type, in
`config/seo.php`:

```php
'url_patterns' => [
    'post' => '/blog/{slug}',
    'news' => '/{year}/{month}/{slug}',
    '*'    => '/{type}/{slug}',
],
```

These must match the routes your frontend actually serves. A content type with no
pattern has no public URL, and SEO treats it as not publicly renderable: no
canonical, no sitemap entry, `noindex`.

## Redirects

Your frontend's 404 page should ask before it renders:

```
GET /seo/redirect-hint?path=/old-page
```

```json
{ "redirect": { "to": "/new-page", "status": 301 } }
```

A `404` response means no rule matched — render your own not-found page. The
endpoint is rate-limited, and chains are already collapsed, so the `to` you get
is the final destination rather than the next hop.

## Sitemaps and robots

`/sitemap.xml`, `/sitemap-{source}.xml` and `/robots.txt` are served by the CMS
and reflect the same content the API returns. Point Search Console at the CMS
origin for these, or proxy them from your frontend — but do not generate a second
set, or the two will disagree.

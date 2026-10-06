# Structured data

Every page emits one connected schema.org `@graph`, not a pile of independent
snippets. Nodes cross-reference each other by `@id`, so a consumer reading the
`Article` can follow it to the `WebPage` it lives on, the `Organization` that
published it and the `ImageObject` it uses.

## Nodes

| Node | Emitted when |
| --- | --- |
| `WebSite` | A site base URL is configured. Carries a `SearchAction` when a site search URL is set. |
| `Organization` / `Person` | The site identity is configured. Which one comes from `knowledge_graph_type`. |
| `WebPage` | Always. |
| `BlogPosting` / `TechArticle` / … | The subject is article-shaped. See below. |
| `BreadcrumbList` | The subject carries breadcrumbs. |
| `ImageObject` | The subject has a social image. |
| `FAQPage` | The source supplied `raw['faq']`. |
| `HowTo` | The source supplied `raw['howto']`. |

## `@id` scheme

```
{base}/#website          the site
{base}/#identity         the organisation or person behind it
{canonical}#webpage      this page
{canonical}#article      the article on it
{canonical}#primaryimage its image
```

## Article subtypes

Emitting the bare `Article` supertype for everything forfeits the distinctions
rich results actually act on, so the subtype comes from the subject's kind:

| Subject type | schema.org type |
| --- | --- |
| `Article` | `BlogPosting` |
| `Doc` | `TechArticle` |
| everything else | no article node |

A source can name a more precise type through `raw['schema_type']`. Values are
checked against an allowlist (`Article`, `BlogPosting`, `NewsArticle`,
`TechArticle`, `ScholarlyArticle`, `Report`); anything else is ignored rather
than trusted into the graph.

## Consistency with the head

The graph is built from the already-resolved `HeadPayload` — canonical from the
emitted `<link rel="canonical">`, description from the emitted meta description.
Structured data therefore cannot disagree with the tags beside it, which is the
most common structured-data fault in the wild.

## Validation

`SchemaValidator` checks the graph locally, with no network call: nodes without a
type, required properties missing, duplicate `@id`s, `@id` references pointing at
nodes that are not in the graph, and output that will not survive `json_encode`.
It runs as a scan check (`schema-invalid`) and as a scorecard invariant, so a
regression fails CI rather than being discovered by a rich-results test months
later.

## Escaping

JSON-LD is encoded with `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS |
JSON_HEX_QUOT`, so a `</script>` in any content value cannot close the block it
is inside.

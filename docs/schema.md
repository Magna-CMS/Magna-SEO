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
| `SoftwareApplication` | The page's SEO override names an app. See below. |

## `@id` scheme

```
{base}/#website          the site
{base}/#identity         the organisation or person behind it
{base}/#logo             the organisation's logo (an ImageObject)
{base}/#software-{name}  an app the site describes, one per app name
{canonical}#webpage      this page
{canonical}#article      the article on it
{canonical}#primaryimage its image
```

## Site identity

`WebSite` carries `name` from the site name and `alternateName` from the
*Alternate site name* setting — search engines read both when choosing the name
shown above a result. `Organization` carries `logo` as an `ImageObject` with the
width and height from the media library, and `image` pointing at it. A person has
no logo; the setting is ignored for one.

## SoftwareApplication

Opt-in per page, from the entry editor's *Structured data: software application*
section, stored under `schema_overrides['software_application']`:

| Key | Becomes | Rule |
| --- | --- | --- |
| `name` | `name` | required, 120 characters; without it nothing is emitted |
| `alternate_names` | `alternateName` | up to 5 |
| `application_category` | `applicationCategory` | one of Google's categories |
| `application_subcategory` | `applicationSubCategory` | 120 characters |
| `operating_systems` | `operatingSystem` | joined with commas |
| `software_version` | `softwareVersion` | 40 characters |
| `download_url`, `install_url`, `screenshot` | same, camel-cased | http(s) or root-relative only |
| `offers` | `offers` | each needs a decimal `price` and an ISO 4217 `currency` |
| `in_language` | `inLanguage` | language tags |
| `feature_list` | `featureList` | up to 30 |

Anything else in the array is ignored. The `@id` comes from the app name, so the
home page and the download page describe one app. There is no `aggregateRating`
or `review`: Google shows software stars only for real ratings, and one a site
writes about itself breaks its guidelines. The validator flags one if it ever
appears.

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

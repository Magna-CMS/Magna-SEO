# Template tokens

Title and description templates are written with `%%token%%` placeholders and
resolved per page. Tokens are case-insensitive, and an unknown one resolves to an
empty string rather than being left in the output — a visitor should never see
`%%sitename%%` in a browser tab because a template referenced something that does
not exist.

| Token | Resolves to |
| --- | --- |
| `%%title%%` | The subject's own title. |
| `%%sitename%%` | `SeoSettings::site_name`. |
| `%%sep%%` | `SeoSettings::title_separator`. |
| `%%excerpt%%` | The subject's excerpt, or a word-safe truncation of its body text. |
| `%%date%%` | Publication date, `Y-m-d`. |
| `%%locale%%` | The subject's locale. |

## Precedence

For every field: **per-entity override → template → nothing**. An editor's value
always wins; the template is what fills the gap for the pages nobody has hand-
written.

## Dangling separators

A template like `%%title%% %%sep%% %%sitename%%` renders as `Title` — not
`Title -` — when the site name is blank. The separator is trimmed when what
follows it resolves to nothing, so a half-configured site does not emit ragged
titles across every page.

## Defaults

```
default_title_template:       %%title%% %%sep%% %%sitename%%
default_description_template: %%excerpt%%
title_separator:              -
```

## Length

Descriptions derived from body text are truncated on a word boundary at
`config('seo.description_length')` (155 by default) with an ellipsis. An explicit
override is never truncated: if an editor wrote it, it is theirs.

The editor's SERP preview measures the *pixel* width of the title and description
against the fonts Google renders with, because that — not character count — is
what determines whether a result is cut off.

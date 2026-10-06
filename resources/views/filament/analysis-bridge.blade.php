{{--
    Feeds the live SEO panel from whatever fields the surrounding entry form
    happens to have.

    A content type defines its own fields, so there is no fixed selector to bind
    to. This reads the form by field-name convention and re-reads it on input,
    debounced — one analysis for a burst of typing rather than one per keystroke.

    It only reads. Nothing here writes to the form, so a bug in this bridge can
    cost an accurate score but never an author's draft.
--}}
<div
    x-data="{
        debounce: null,

        read() {
            const form = this.$root.closest('form') ?? document

            // Fields are matched by scanning attributes rather than with an
            // attribute selector. `[wire\:model*='data.title']` matches only an
            // attribute named exactly `wire:model`, and Filament renders
            // modifiers into the name itself — `wire:model.live.blur` — so the
            // selector silently matched nothing and every post scored as empty.
            const field = (scope, name) => {
                for (const el of scope.querySelectorAll('input, textarea, select')) {
                    if ((el.getAttribute('name') ?? '').includes(name)) return el
                    if (el.dataset.field === name) return el

                    for (const attr of el.attributes) {
                        if (attr.name.startsWith('wire:model') && attr.value.includes(name)) return el
                    }
                }

                return null
            }

            // The surrounding form first, then the whole page. An editor may
            // split its canvas and its sidebar into two separate forms — the
            // blog builder does — which puts the title and slug out of reach of
            // a form-scoped lookup.
            const value = (names) => {
                for (const name of names) {
                    for (const scope of [form, document]) {
                        const found = field(scope, name)

                        if (found && typeof found.value === 'string' && found.value !== '') {
                            return found.value
                        }
                    }
                }

                return ''
            }

            return {
                title: value(['seo_title']) || value(['data.title', 'title', 'name', 'heading']),
                description: value(['seo_description']) || value(['data.excerpt', 'excerpt', 'summary']),
                slug: value(['data.slug', 'slug']),
                body: value(['data.body', 'body', 'content', 'text']) || this.richText(),
                keyword: value(['seo_focus_keyword']),
            }
        },

        // Block editors keep their content as JSON in a hidden input, and
        // analysing that JSON would count its own keys as prose. The rendered
        // editor is the real text, so read that instead. Read-only, like the
        // rest of this bridge.
        richText() {
            const editor = document.querySelector('.codex-editor__redactor, .ProseMirror, [contenteditable=\'true\']')

            return editor ? (editor.innerText ?? '').trim() : ''
        },

        push() {
            window.dispatchEvent(new CustomEvent('seo-content-changed', { detail: this.read() }))
        },

        init() {
            this.$nextTick(() => this.push())

            const form = this.$root.closest('form') ?? document

            form.addEventListener('input', () => {
                clearTimeout(this.debounce)
                this.debounce = setTimeout(() => this.push(), 750)
            })
        },
    }"
    class="hidden"
></div>

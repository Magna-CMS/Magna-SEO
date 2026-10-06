{{--
    Google-style result preview.

    Google truncates a result by *pixel* width, not character count, so a
    character-count preview lies for any title with wide or narrow letters. The
    width is measured here with canvas measureText against the font metrics
    Google actually renders with, and the desktop and mobile limits differ, so
    both are shown.
--}}
<div
    x-data="{
        title: '',
        description: '',
        url: '',
        device: 'desktop',

        limits: {
            desktop: { title: 600, description: 920, titleFont: '20px Arial, sans-serif', descriptionFont: '14px Arial, sans-serif' },
            mobile: { title: 460, description: 830, titleFont: '18px Arial, sans-serif', descriptionFont: '14px Arial, sans-serif' },
        },

        get limit() { return this.limits[this.device] },

        measure(text, font) {
            this.canvas = this.canvas || document.createElement('canvas')
            const context = this.canvas.getContext('2d')
            context.font = font

            return context.measureText(text).width
        },

        clip(text, font, maxWidth) {
            if (! text) return ''
            if (this.measure(text, font) <= maxWidth) return text

            let clipped = text
            while (clipped.length > 1 && this.measure(clipped + '…', font) > maxWidth) {
                clipped = clipped.slice(0, -1)
            }

            return clipped.replace(/\s+\S*$/, '') + '…'
        },

        get clippedTitle() { return this.clip(this.title, this.limit.titleFont, this.limit.title) },
        get clippedDescription() { return this.clip(this.description, this.limit.descriptionFont, this.limit.description) },
        get titleTruncated() { return this.clippedTitle !== this.title },
        get descriptionTruncated() { return this.clippedDescription !== this.description },

        sync() {
            const root = this.$root.closest('form') ?? document

            // Attributes are scanned rather than selected. `[wire\:model*='…']`
            // matches only an attribute named exactly `wire:model`, and Filament
            // renders modifiers into the name — `wire:model.live.blur` — so the
            // selector matched nothing and every post fell back to the
            // placeholder title instead of the one being written.
            //
            // Both scopes are tried because an editor may split its canvas and
            // its sidebar into two separate forms, as the blog builder does.
            const read = (names, fallback) => {
                for (const name of names) {
                    for (const scope of [root, document]) {
                        for (const el of scope.querySelectorAll('input, textarea, select')) {
                            const named = (el.getAttribute('name') ?? '').includes(name)
                                || [...el.attributes].some(
                                    (attr) => attr.name.startsWith('wire:model') && attr.value.includes(name)
                                )

                            if (named && el.value) return el.value
                        }
                    }
                }

                return fallback
            }

            this.title = read(['seo_title'], '') || read(['data.title', 'title'], 'Untitled')
            this.description = read(['seo_description'], '') || read(['data.excerpt', 'excerpt'], '')
            this.url = read(['data.slug', 'slug'], '')
        },

        init() {
            this.sync()
            const root = this.$root.closest('form') ?? document
            root.addEventListener('input', () => this.sync())
        },
    }"
    class="fi-fo-field-wrp"
>
    <div class="flex items-center justify-between gap-2 mb-2">
        <span class="text-sm font-medium text-gray-950 dark:text-white">Search result preview</span>

        <div class="flex gap-1 text-xs">
            <button type="button" @click="device = 'desktop'"
                :class="device === 'desktop' ? 'font-semibold underline' : 'opacity-60'">Desktop</button>
            <span class="opacity-40">/</span>
            <button type="button" @click="device = 'mobile'"
                :class="device === 'mobile' ? 'font-semibold underline' : 'opacity-60'">Mobile</button>
        </div>
    </div>

    <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5"
         :style="`max-width: ${limit.title + 60}px`">
        <div class="truncate text-xs text-gray-600 dark:text-gray-400" x-text="url || '/'"></div>

        <div class="mt-1 text-lg leading-snug text-[#1a0dab] dark:text-[#8ab4f8]" x-text="clippedTitle"></div>

        <div class="mt-1 text-sm leading-snug text-gray-700 dark:text-gray-300" x-text="clippedDescription"></div>
    </div>

    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
        <span x-show="titleTruncated">The title is cut off on <span x-text="device"></span>. </span>
        <span x-show="descriptionTruncated">The description is cut off on <span x-text="device"></span>. </span>
        <span x-show="! titleTruncated && ! descriptionTruncated">Both fit on <span x-text="device"></span>.</span>
    </p>
</div>

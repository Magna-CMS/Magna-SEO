{{--
    How this page looks when someone shares it.

    Facebook and X crop and truncate differently, so one preview would be a lie
    for at least one of them. Both are shown, at their real aspect ratio, because
    the usual failure is not a missing image — it is an image whose subject is
    outside the crop.
--}}
<div
    x-data="{
        network: 'facebook',
        title: '',
        description: '',
        image: '',
        host: window.location.host,

        limits: {
            facebook: { title: 88, description: 200 },
            x: { title: 70, description: 120 },
        },

        clip(text, max) {
            if (! text) return ''
            return text.length <= max ? text : text.slice(0, max).replace(/\s+\S*$/, '') + '…'
        },

        get shownTitle() { return this.clip(this.title, this.limits[this.network].title) },
        get shownDescription() { return this.clip(this.description, this.limits[this.network].description) },

        sync(detail) {
            this.title = detail.title || ''
            this.description = detail.description || ''
        },

        init() {
            window.addEventListener('seo-content-changed', (event) => this.sync(event.detail))
        },
    }"
    class="fi-fo-field-wrp"
>
    <div class="mb-2 flex items-center justify-between gap-2">
        <span class="text-sm font-medium text-gray-950 dark:text-white">Social preview</span>

        <div class="flex gap-1 text-xs">
            <button type="button" @click="network = 'facebook'"
                :class="network === 'facebook' ? 'font-semibold underline' : 'opacity-60'">Facebook</button>
            <span class="opacity-40">/</span>
            <button type="button" @click="network = 'x'"
                :class="network === 'x' ? 'font-semibold underline' : 'opacity-60'">X</button>
        </div>
    </div>

    <div class="max-w-md overflow-hidden rounded-xl border border-gray-200 dark:border-white/10">
        <div class="flex aspect-[1.91/1] items-center justify-center bg-gray-100 text-xs text-gray-400 dark:bg-white/5">
            <template x-if="image"><img :src="image" alt="" class="h-full w-full object-cover"></template>
            <template x-if="! image"><span>1200 × 630 share image</span></template>
        </div>

        <div class="space-y-1 bg-gray-50 p-3 dark:bg-white/5">
            <div class="text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400" x-text="host"></div>
            <div class="text-sm font-semibold leading-snug text-gray-950 dark:text-white" x-text="shownTitle"></div>
            <div class="text-xs leading-snug text-gray-600 dark:text-gray-300" x-text="shownDescription"></div>
        </div>
    </div>

    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
        Both networks crop to roughly 1.91:1. Keep faces and text away from the edges.
    </p>
</div>

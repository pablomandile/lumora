<script setup lang="ts">
import { Head, InfiniteScroll, Link, router } from '@inertiajs/vue3';
import { Heart, ImageOff, Search, Sparkles, X } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import ImageViewer from '@/components/images/ImageViewer.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { requestGeneration } from '@/lib/generate';
import { dashboard, gallery } from '@/routes';
import type { ImageItem, PromptPreset } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Galería', href: gallery() }],
    },
});

const props = defineProps<{
    images: { data: ImageItem[] };
    filters: { q: string; favoritas: boolean };
    total: number;
}>();

const search = ref(props.filters.q);
const viewing = ref<string | null>(null);

// Las listas de scroll infinito se van sumando en el cliente: los cambios
// (favorita, borrada) se aplican acá en vez de recargar todo.
const removed = reactive(new Set<string>());
const favorites = reactive(new Map<string, boolean>());

const items = computed<ImageItem[]>(() =>
    props.images.data
        .filter((image) => !removed.has(image.id))
        .map((image) =>
            favorites.has(image.id)
                ? { ...image, favorite: favorites.get(image.id)! }
                : image,
        )
        .filter((image) => !props.filters.favoritas || image.favorite),
);

function apply(params: { q?: string; favoritas?: boolean }): void {
    const q = params.q ?? search.value;
    const favoritas = params.favoritas ?? props.filters.favoritas;

    router.get(
        gallery().url,
        { ...(q ? { q } : {}), ...(favoritas ? { favoritas: 1 } : {}) },
        {
            preserveState: true,
            preserveScroll: false,
            replace: true,
            reset: ['images'],
            onSuccess: () => {
                removed.clear();
                favorites.clear();
            },
        },
    );
}

let debounce: ReturnType<typeof setTimeout> | undefined;
watch(search, (value) => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        if (value.trim() !== props.filters.q) {
            apply({ q: value.trim() });
        }
    }, 350);
});

function reuse(preset: PromptPreset): void {
    router.visit(
        dashboard({
            query: {
                prompt: preset.prompt,
                ...(preset.aspect ? { aspect: preset.aspect } : {}),
                ...(preset.seed != null ? { seed: preset.seed } : {}),
            },
        }).url,
    );
}

function vary(image: ImageItem): void {
    requestGeneration({
        prompt: image.prompt,
        aspect: image.aspect,
        width: image.width,
        height: image.height,
    });
}
</script>

<template>
    <Head title="Galería" />

    <div class="flex flex-1 flex-col gap-4 px-3 py-4 sm:px-4">
        <div class="flex flex-wrap items-center gap-2">
            <div class="relative min-w-0 flex-1 sm:max-w-sm">
                <Search
                    class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="search"
                    type="search"
                    placeholder="Buscar por prompt…"
                    class="pl-8"
                    aria-label="Buscar por prompt"
                    data-test="gallery-search"
                />
            </div>
            <Button
                :variant="filters.favoritas ? 'default' : 'outline'"
                :aria-pressed="filters.favoritas"
                data-test="favorites-filter"
                @click="apply({ favoritas: !filters.favoritas })"
            >
                <Heart :class="filters.favoritas && 'fill-current'" />
                Favoritas
            </Button>
            <span class="ml-auto text-xs text-muted-foreground">
                {{ total }} {{ total === 1 ? 'imagen' : 'imágenes' }}
            </span>
        </div>

        <InfiniteScroll
            v-if="items.length"
            data="images"
            :buffer="600"
            class="grid grid-cols-2 gap-2 sm:grid-cols-3 sm:gap-3 lg:grid-cols-4 xl:grid-cols-5"
            data-test="gallery-grid"
        >
            <button
                v-for="image in items"
                :key="image.id"
                type="button"
                class="group relative aspect-square overflow-hidden rounded-lg border bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :aria-label="`Ver imagen: ${image.prompt}`"
                data-test="gallery-item"
                @click="viewing = image.id"
            >
                <img
                    :src="image.thumb_url"
                    :alt="image.prompt"
                    loading="lazy"
                    class="size-full object-cover transition-transform duration-300 group-hover:scale-[1.03]"
                />
                <Heart
                    v-if="image.favorite"
                    class="absolute top-1.5 right-1.5 size-4 fill-rose-500 text-rose-500 drop-shadow"
                />
            </button>

            <template #loading>
                <div class="col-span-full flex justify-center py-6">
                    <Spinner />
                </div>
            </template>
        </InfiniteScroll>

        <div
            v-else
            class="mx-auto flex max-w-sm flex-col items-center gap-3 py-16 text-center"
        >
            <ImageOff class="size-8 text-muted-foreground" />
            <template v-if="filters.q || filters.favoritas">
                <p class="text-sm text-muted-foreground">
                    No hay imágenes que coincidan.
                </p>
                <Button
                    variant="outline"
                    size="sm"
                    @click="
                        search = '';
                        apply({ q: '', favoritas: false });
                    "
                >
                    <X />
                    Quitar filtros
                </Button>
            </template>
            <template v-else>
                <p class="text-sm text-muted-foreground">
                    Todavía no generaste imágenes.
                </p>
                <Button as-child>
                    <Link :href="dashboard()">
                        <Sparkles />
                        Ir al estudio
                    </Link>
                </Button>
            </template>
        </div>
    </div>

    <ImageViewer
        v-model="viewing"
        :images="items"
        :reload="['total']"
        @reuse="reuse"
        @vary="vary"
        @favorited="(image, value) => favorites.set(image.id, value)"
        @deleted="(image) => removed.add(image.id)"
    />
</template>

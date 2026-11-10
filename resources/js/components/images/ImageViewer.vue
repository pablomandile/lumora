<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    ChevronLeft,
    ChevronRight,
    Copy,
    Dices,
    Download,
    Heart,
    Repeat,
    Trash2,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatBytes, formatDate } from '@/lib/format';
import { destroy, favorite } from '@/routes/images';
import type { ImageFormat, ImageItem, PromptPreset } from '@/types';

const props = defineProps<{
    images: ImageItem[];
    /** Props de la página a recargar después de marcar favorita o borrar. */
    reload: string[];
}>();

const emit = defineEmits<{
    reuse: [preset: PromptPreset];
    vary: [image: ImageItem];
    favorited: [image: ImageItem, value: boolean];
    deleted: [image: ImageItem];
}>();

/** Id de la imagen abierta (null = cerrado). */
const openId = defineModel<string | null>({ default: null });

const index = computed(() =>
    props.images.findIndex((image) => image.id === openId.value),
);
const image = computed<ImageItem | null>(
    () => props.images[index.value] ?? null,
);
const open = computed({
    get: () => image.value !== null,
    set: (value) => {
        if (!value) {
            openId.value = null;
        }
    },
});

const zoomed = ref(false);
const confirmingDelete = ref(false);
const busy = ref(false);

watch(openId, () => {
    zoomed.value = false;
    confirmingDelete.value = false;
});

// Si la imagen abierta desaparece de la lista (se borró), se cierra.
watch(
    () => props.images,
    () => {
        if (openId.value && index.value === -1) {
            openId.value = null;
        }
    },
);

const formats: { key: ImageFormat; label: string; hint: string }[] = [
    { key: 'png', label: 'PNG', hint: 'Original, sin pérdida' },
    { key: 'jpg', label: 'JPG', hint: 'Liviano, para compartir' },
    { key: 'webp', label: 'WebP', hint: 'El más liviano' },
];

function go(step: number): void {
    const next = props.images[index.value + step];

    if (next) {
        openId.value = next.id;
    }
}

function onKey(event: KeyboardEvent): void {
    if (!open.value || event.target instanceof HTMLTextAreaElement) {
        return;
    }

    if (event.key === 'ArrowLeft') {
        go(-1);
    } else if (event.key === 'ArrowRight') {
        go(1);
    }
}

onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));

async function copy(text: string, what: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(`${what} copiado.`);
    } catch {
        toast.error('No se pudo copiar.');
    }
}

function toggleFavorite(): void {
    const current = image.value;

    if (!current || busy.value) {
        return;
    }

    busy.value = true;
    router.patch(
        favorite(current.id).url,
        { favorite: !current.favorite },
        {
            preserveScroll: true,
            preserveState: true,
            only: props.reload,
            onSuccess: () => emit('favorited', current, !current.favorite),
            onFinish: () => (busy.value = false),
        },
    );
}

function remove(): void {
    const current = image.value;

    if (!current || busy.value) {
        return;
    }

    if (!confirmingDelete.value) {
        confirmingDelete.value = true;

        return;
    }

    busy.value = true;
    // Se pasa a la vecina antes de borrar, así el visor sigue abierto.
    const neighbour =
        props.images[index.value + 1] ?? props.images[index.value - 1];
    router.delete(destroy(current.id).url, {
        preserveScroll: true,
        preserveState: true,
        only: props.reload,
        onSuccess: () => {
            openId.value = neighbour?.id ?? null;
            emit('deleted', current);
        },
        onFinish: () => {
            busy.value = false;
            confirmingDelete.value = false;
        },
    });
}

function reuse(withSeed: boolean): void {
    if (!image.value) {
        return;
    }

    emit('reuse', {
        prompt: image.value.prompt,
        aspect: image.value.aspect,
        seed: withSeed ? image.value.seed : null,
    });
    openId.value = null;
}

function vary(): void {
    if (image.value) {
        emit('vary', image.value);
        openId.value = null;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            v-if="image"
            class="grid max-h-[calc(100dvh-1rem)] max-w-[calc(100%-1rem)] grid-rows-[minmax(0,1fr)_auto] gap-0 overflow-hidden p-0 sm:max-w-6xl lg:h-[min(52rem,calc(100dvh-2rem))] lg:grid-cols-[minmax(0,1fr)_21rem] lg:grid-rows-1"
            data-test="image-viewer"
        >
            <DialogTitle class="sr-only">Imagen generada</DialogTitle>
            <DialogDescription class="sr-only">{{
                image.prompt
            }}</DialogDescription>

            <!-- Imagen -->
            <div
                class="relative flex min-h-[40dvh] items-center justify-center bg-zinc-950"
            >
                <div
                    class="size-full"
                    :class="
                        zoomed
                            ? 'cursor-zoom-out overflow-auto'
                            : 'flex cursor-zoom-in items-center justify-center'
                    "
                    @click="zoomed = !zoomed"
                >
                    <img
                        :key="image.id"
                        :src="image.url"
                        :alt="image.prompt"
                        :width="image.width"
                        :height="image.height"
                        :class="
                            zoomed
                                ? 'max-w-none'
                                : 'max-h-[55dvh] w-auto max-w-full object-contain lg:max-h-[calc(min(52rem,100dvh-2rem))]'
                        "
                        :style="{
                            backgroundImage: `url(${image.thumb_url})`,
                            backgroundSize: 'cover',
                        }"
                    />
                </div>

                <Button
                    v-if="index > 0"
                    variant="secondary"
                    size="icon"
                    class="absolute top-1/2 left-2 -translate-y-1/2 rounded-full opacity-80 hover:opacity-100"
                    aria-label="Anterior"
                    @click.stop="go(-1)"
                >
                    <ChevronLeft />
                </Button>
                <Button
                    v-if="index < images.length - 1"
                    variant="secondary"
                    size="icon"
                    class="absolute top-1/2 right-2 -translate-y-1/2 rounded-full opacity-80 hover:opacity-100"
                    aria-label="Siguiente"
                    @click.stop="go(1)"
                >
                    <ChevronRight />
                </Button>
            </div>

            <!-- Datos y acciones -->
            <div
                class="flex min-h-0 flex-col gap-4 overflow-y-auto border-t p-4 lg:border-t-0 lg:border-l lg:p-5"
            >
                <div class="pr-8">
                    <div class="flex items-start gap-2">
                        <p
                            class="max-h-40 flex-1 overflow-y-auto text-sm leading-relaxed break-words whitespace-pre-line"
                            data-test="viewer-prompt"
                        >
                            {{ image.prompt }}
                        </p>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            class="shrink-0"
                            aria-label="Copiar prompt"
                            @click="copy(image.prompt, 'Prompt')"
                        >
                            <Copy />
                        </Button>
                    </div>
                </div>

                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-xs">
                    <dt class="text-muted-foreground">Tamaño</dt>
                    <dd>
                        {{ image.width }}×{{ image.height }}
                        <span v-if="image.aspect" class="text-muted-foreground"
                            >· {{ image.aspect }}</span
                        >
                    </dd>
                    <template v-if="image.seed !== null">
                        <dt class="text-muted-foreground">Semilla</dt>
                        <dd class="flex items-center gap-1">
                            <span class="font-mono">{{ image.seed }}</span>
                            <button
                                type="button"
                                class="rounded p-0.5 text-muted-foreground hover:text-foreground"
                                aria-label="Copiar semilla"
                                @click="copy(String(image.seed), 'Semilla')"
                            >
                                <Copy class="size-3" />
                            </button>
                        </dd>
                    </template>
                    <dt class="text-muted-foreground">Modelo</dt>
                    <dd>
                        {{ image.model
                        }}<template v-if="image.steps">
                            · {{ image.steps }} pasos</template
                        >
                    </dd>
                    <template v-if="image.run_seconds">
                        <dt class="text-muted-foreground">Tardó</dt>
                        <dd>{{ Math.round(image.run_seconds) }} s</dd>
                    </template>
                    <dt class="text-muted-foreground">Archivo</dt>
                    <dd>PNG · {{ formatBytes(image.bytes) }}</dd>
                    <template v-if="image.created_at">
                        <dt class="text-muted-foreground">Creada</dt>
                        <dd>{{ formatDate(image.created_at) }}</dd>
                    </template>
                </dl>

                <div class="mt-auto grid grid-cols-2 gap-2">
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button class="col-span-2" data-test="download">
                                <Download />
                                Descargar
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="center" class="w-56">
                            <DropdownMenuItem
                                v-for="format in formats"
                                :key="format.key"
                                as-child
                            >
                                <a
                                    :href="image.downloads[format.key]"
                                    download
                                    class="flex flex-col items-start gap-0"
                                >
                                    <span class="font-medium">{{
                                        format.label
                                    }}</span>
                                    <span class="text-xs text-muted-foreground">
                                        {{ format.hint }}
                                    </span>
                                </a>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <Button variant="outline" @click="reuse(false)">
                        <Repeat />
                        Reusar prompt
                    </Button>
                    <Button variant="outline" data-test="vary" @click="vary">
                        <Dices />
                        Otra variante
                    </Button>
                    <Button
                        v-if="image.seed !== null"
                        variant="ghost"
                        size="sm"
                        class="col-span-2 text-muted-foreground"
                        @click="reuse(true)"
                    >
                        Reusar con la misma semilla
                    </Button>

                    <Button
                        variant="outline"
                        :aria-pressed="image.favorite"
                        :disabled="busy"
                        data-test="favorite"
                        @click="toggleFavorite"
                    >
                        <Heart
                            :class="
                                image.favorite && 'fill-rose-500 text-rose-500'
                            "
                        />
                        Favorita
                    </Button>
                    <Button
                        :variant="confirmingDelete ? 'destructive' : 'outline'"
                        :disabled="busy"
                        data-test="delete"
                        @click="remove"
                    >
                        <Trash2 />
                        {{ confirmingDelete ? '¿Eliminar?' : 'Eliminar' }}
                    </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>

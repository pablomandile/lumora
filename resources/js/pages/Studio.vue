<script setup lang="ts">
import { Head, Link, router, usePage, usePoll } from '@inertiajs/vue3';
import { Images, Sparkles } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import ImageViewer from '@/components/images/ImageViewer.vue';
import GenerationCard from '@/components/studio/GenerationCard.vue';
import PromptBar from '@/components/studio/PromptBar.vue';
import { requestGeneration } from '@/lib/generate';
import { dashboard, gallery } from '@/routes';
import { destroy as destroyGeneration } from '@/routes/generations';
import type {
    AspectOption,
    GenerationItem,
    ImageItem,
    ModelOption,
    PromptPreset,
    Quota,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Estudio', href: dashboard() }],
    },
});

const props = defineProps<{
    generations: GenerationItem[];
    quota: Quota;
    has_more: boolean;
    models: ModelOption[];
    aspects: AspectOption[];
    max_side: number;
    prefill: { prompt: string; aspect: string | null; seed: number | null };
}>();

const RELOAD = ['generations', 'quota', 'has_more'];

const page = usePage();
const isAdmin = computed(() => page.props.auth.is_admin);
const bar = ref<InstanceType<typeof PromptBar> | null>(null);
const top = ref<HTMLElement | null>(null);
const viewing = ref<string | null>(null);

const images = computed<ImageItem[]>(() =>
    props.generations.flatMap((g) => (g.image ? [g.image] : [])),
);

const hasPending = computed(() =>
    props.generations.some((g) =>
        ['queued', 'waiting_gpu', 'running'].includes(g.status),
    ),
);

// Mientras haya algo pendiente se consulta cada 3 s.
const poll = usePoll(
    3000,
    { only: ['generations', 'quota'] },
    { autoStart: false },
);

watch(hasPending, (pending) => (pending ? poll.start() : poll.stop()), {
    immediate: true,
});

const examples = [
    'Un faro en un acantilado al atardecer, olas rompiendo, luz dorada, fotografía',
    'Retrato de un gato con anteojos leyendo un libro, ilustración acuarela',
    'Una calle de Buenos Aires en los años 50 bajo la lluvia, cine en blanco y negro',
    'Cartel minimalista que dice "LUMORA" con letras de neón violeta',
];

function fill(preset: PromptPreset): void {
    bar.value?.fill(preset);
}

function onSubmitted(): void {
    top.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function vary(image: ImageItem): void {
    requestGeneration(
        {
            prompt: image.prompt,
            aspect: image.aspect,
            width: image.width,
            height: image.height,
        },
        RELOAD,
    );
    onSubmitted();
}

function retry(generation: GenerationItem): void {
    requestGeneration(
        {
            prompt: generation.prompt,
            aspect: generation.aspect,
            width: generation.width,
            height: generation.height,
        },
        RELOAD,
    );
}

function dismiss(generation: GenerationItem): void {
    router.delete(destroyGeneration(generation.id).url, {
        preserveScroll: true,
        only: RELOAD,
    });
}

onMounted(() => {
    if (props.prefill.prompt) {
        fill(props.prefill);
        // Se limpia la URL para que recargar no vuelva a cargar el prompt.
        window.history.replaceState(window.history.state, '', dashboard().url);
    }
});
</script>

<template>
    <Head title="Estudio" />

    <div class="relative flex flex-1 flex-col">
        <div ref="top" class="scroll-mt-20" />

        <div class="flex-1 px-3 pt-4 pb-8 sm:px-4">
            <div
                v-if="generations.length"
                class="columns-2 gap-3 sm:columns-3 xl:columns-4"
                data-test="generations"
            >
                <GenerationCard
                    v-for="generation in generations"
                    :key="generation.id"
                    :generation="generation"
                    @open="viewing = generation.image?.id ?? null"
                    @retry="retry(generation)"
                    @dismiss="dismiss(generation)"
                />
            </div>

            <div
                v-else
                class="mx-auto flex max-w-xl flex-col items-center gap-5 py-10 text-center sm:py-16"
            >
                <div
                    class="flex size-14 items-center justify-center rounded-2xl bg-gradient-to-br from-violet-500 to-sky-500 text-white shadow-lg"
                >
                    <Sparkles class="size-7" />
                </div>
                <div class="space-y-1.5">
                    <h1 class="text-xl font-semibold">¿Qué imagen creamos?</h1>
                    <p class="text-sm text-muted-foreground">
                        Escribí una descripción abajo y tocá el botón. Cuanto
                        más detalle (lugar, luz, estilo), mejor sale.
                    </p>
                </div>
                <div class="flex flex-wrap justify-center gap-2">
                    <button
                        v-for="example in examples"
                        :key="example"
                        type="button"
                        class="max-w-full rounded-full border px-3 py-1.5 text-left text-xs text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                        @click="fill({ prompt: example })"
                    >
                        {{ example }}
                    </button>
                </div>
            </div>

            <div v-if="has_more" class="mt-4 flex justify-center">
                <Link
                    :href="gallery()"
                    class="inline-flex items-center gap-2 rounded-full border px-4 py-2 text-sm text-muted-foreground hover:bg-accent hover:text-foreground"
                >
                    <Images class="size-4" />
                    Ver todas en la galería
                </Link>
            </div>
        </div>

        <div
            class="sticky bottom-0 z-10 bg-gradient-to-t from-background via-background/90 to-transparent px-2 pt-6 pb-[max(env(safe-area-inset-bottom),0.75rem)] sm:px-4"
        >
            <PromptBar
                ref="bar"
                :aspects="aspects"
                :models="models"
                :quota="quota"
                :is-admin="isAdmin"
                :max-side="max_side"
                @submitted="onSubmitted"
            />
        </div>
    </div>

    <ImageViewer
        v-model="viewing"
        :images="images"
        :reload="RELOAD"
        @reuse="fill"
        @vary="vary"
    />
</template>

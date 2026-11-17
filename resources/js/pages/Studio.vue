<script setup lang="ts">
import { Head, router, usePage, usePoll } from '@inertiajs/vue3';
import { Expand, PencilLine, Shuffle, Sparkles, Wand } from '@lucide/vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import ImageViewer from '@/components/images/ImageViewer.vue';
import GenerationCard from '@/components/studio/GenerationCard.vue';
import PromptBar from '@/components/studio/PromptBar.vue';
import { Button } from '@/components/ui/button';
import { requestGeneration } from '@/lib/generate';
import { dashboard } from '@/routes';
import { destroy as destroyGeneration } from '@/routes/generations';
import type {
    AspectOption,
    ConversationItem,
    GenerationItem,
    ImageItem,
    ModelOption,
    PromptPreset,
    Quota,
    ReferenceThumb,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Estudio', href: dashboard() }],
    },
});

const props = defineProps<{
    /** null = conversación nueva, todavía sin pedidos. */
    conversation: ConversationItem | null;
    generations: GenerationItem[];
    quota: Quota;
    models: ModelOption[];
    aspects: AspectOption[];
    max_side: number;
    prefill: {
        prompt: string;
        aspect: string | null;
        seed: number | null;
        edit_image: ReferenceThumb | null;
        model: string | null;
        attachments: ReferenceThumb[];
    };
}>();

const RELOAD = ['generations', 'quota'];

const page = usePage();
const isAdmin = computed(() => page.props.auth.is_admin);
const bar = ref<InstanceType<typeof PromptBar> | null>(null);
const bottom = ref<HTMLElement | null>(null);
const viewing = ref<string | null>(null);

/** Si hay un modelo de edición disponible (por ahora, solo el admin). */
const canEdit = computed(() => props.models.some((m) => m.max_images > 0));

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

function details(g: GenerationItem): string {
    const parts = [
        g.attachments.length
            ? 'Edición'
            : (g.aspect ?? `${g.width}×${g.height}`),
    ];

    if (g.seed != null) {
        parts.push(`semilla ${g.seed}`);
    }

    return parts.join(' · ');
}

/** Las verticales más angostas, para que entren en la pantalla. */
function cardWidth(g: GenerationItem): string {
    if (g.height > g.width) {
        return 'max-w-[16rem] sm:max-w-xs';
    }

    return g.width > g.height ? 'max-w-lg' : 'max-w-sm';
}

function scrollToBottom(smooth = true): void {
    nextTick(() =>
        bottom.value?.scrollIntoView({
            behavior: smooth ? 'smooth' : 'auto',
            block: 'end',
        }),
    );
}

// Un pedido nuevo (propio o una variante) lleva al final del hilo.
watch(
    () => props.generations.length,
    (now, before) => {
        if (now > before) {
            scrollToBottom();
        }
    },
);

function fill(preset: PromptPreset): void {
    bar.value?.fill(preset);
}

function regenerate(g: {
    prompt: string;
    model: string;
    aspect: string | null;
    width: number;
    height: number;
    attachments: ReferenceThumb[];
}): void {
    requestGeneration(
        {
            prompt: g.prompt,
            model: g.model,
            aspect: g.aspect,
            width: g.width,
            height: g.height,
            // Otra variante de una edición: con las mismas imágenes.
            attachment_ids: g.attachments.map((a) => a.id),
            conversation_id: props.conversation?.id ?? null,
        },
        props.conversation ? RELOAD : undefined,
    );
}

/** "Editar esta imagen": pasa a la barra como imagen 1. */
function editImage(image: ReferenceThumb): void {
    bar.value?.editImage(image);
}

function dismiss(generation: GenerationItem): void {
    router.delete(destroyGeneration(generation.id).url, {
        preserveScroll: true,
        only: RELOAD,
    });
}

onMounted(() => {
    scrollToBottom(false);

    const { prefill } = props;

    if (prefill.prompt) {
        fill(prefill);
    }

    if (prefill.edit_image) {
        editImage(prefill.edit_image);
    }

    if (prefill.prompt || prefill.edit_image) {
        // Se limpia la URL para que recargar no vuelva a cargar lo mismo.
        window.history.replaceState(window.history.state, '', dashboard().url);
    }
});
</script>

<template>
    <Head :title="conversation?.title ?? 'Nueva conversación'" />

    <div class="relative flex flex-1 flex-col">
        <div class="flex-1 px-3 pt-4 pb-8 sm:px-4">
            <div
                v-if="generations.length"
                class="mx-auto flex w-full max-w-3xl flex-col gap-6"
                data-test="thread"
            >
                <h1
                    v-if="conversation"
                    class="truncate text-center text-sm font-medium text-muted-foreground"
                >
                    {{ conversation.title }}
                </h1>

                <div
                    v-for="generation in generations"
                    :key="generation.id"
                    class="flex flex-col gap-3"
                    data-test="turn"
                >
                    <!-- El pedido -->
                    <div class="flex justify-end">
                        <div
                            class="max-w-[85%] rounded-2xl rounded-br-md bg-muted px-4 py-2.5"
                        >
                            <div
                                v-if="generation.attachments.length"
                                class="mb-2 flex flex-wrap justify-end gap-1.5"
                                data-test="turn-references"
                            >
                                <a
                                    v-for="(
                                        attachment, index
                                    ) in generation.attachments"
                                    :key="attachment.id"
                                    :href="attachment.url"
                                    target="_blank"
                                    rel="noopener"
                                    class="block rounded-lg focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    <img
                                        :src="attachment.thumb_url"
                                        :alt="`Imagen ${index + 1} del pedido`"
                                        loading="lazy"
                                        class="size-16 rounded-lg border object-cover"
                                    />
                                </a>
                            </div>
                            <p
                                class="text-sm break-words whitespace-pre-wrap"
                                data-test="turn-prompt"
                            >
                                {{ generation.prompt }}
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ details(generation) }}
                            </p>
                        </div>
                    </div>

                    <!-- La respuesta -->
                    <div class="flex items-start gap-2.5">
                        <AppLogoIcon class="mt-1 size-7 shrink-0" />
                        <div class="min-w-0 flex-1">
                            <div :class="cardWidth(generation)">
                                <GenerationCard
                                    :generation="generation"
                                    @open="
                                        viewing = generation.image?.id ?? null
                                    "
                                    @retry="regenerate(generation)"
                                    @dismiss="dismiss(generation)"
                                />
                            </div>
                            <div
                                v-if="generation.image"
                                class="-mt-1 flex flex-wrap gap-1"
                            >
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    class="text-muted-foreground"
                                    @click="regenerate(generation)"
                                >
                                    <Shuffle />
                                    Otra variante
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    class="text-muted-foreground"
                                    @click="
                                        fill({
                                            prompt: generation.prompt,
                                            aspect: generation.aspect,
                                            model: generation.model,
                                            attachments: generation.attachments,
                                        })
                                    "
                                >
                                    <PencilLine />
                                    Editar prompt
                                </Button>
                                <Button
                                    v-if="canEdit"
                                    size="sm"
                                    variant="ghost"
                                    class="text-muted-foreground"
                                    data-test="edit-image"
                                    @click="editImage(generation.image)"
                                >
                                    <Wand />
                                    Editar esta imagen
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    class="text-muted-foreground"
                                    @click="viewing = generation.image.id"
                                >
                                    <Expand />
                                    Ver y descargar
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div
                v-else
                class="mx-auto flex max-w-xl flex-col items-center gap-5 py-10 text-center sm:py-16"
            >
                <div
                    class="flex size-14 items-center justify-center rounded-2xl bg-gradient-to-br from-amber-400 via-orange-500 to-pink-500 text-white shadow-lg"
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

            <div ref="bottom" class="scroll-mb-40" />
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
                :conversation-id="conversation?.id ?? null"
                @submitted="scrollToBottom()"
            />
        </div>
    </div>

    <ImageViewer
        v-model="viewing"
        :images="images"
        :reload="RELOAD"
        :can-edit="canEdit"
        @reuse="fill"
        @vary="regenerate"
        @edit="editImage"
    />
</template>

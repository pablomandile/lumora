<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    ArrowUp,
    Dices,
    ImagePlus,
    SlidersHorizontal,
    Wand,
    X,
} from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import AspectIcon from '@/components/studio/AspectIcon.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/generations';
import type {
    AspectOption,
    ModelOption,
    PromptPreset,
    Quota,
    ReferenceThumb,
} from '@/types';

const props = defineProps<{
    aspects: AspectOption[];
    models: ModelOption[];
    quota: Quota;
    isAdmin: boolean;
    maxSide: number;
    /** null = conversación nueva: el primer pedido la crea. */
    conversationId: string | null;
}>();

const emit = defineEmits<{ submitted: [] }>();

const PROMPT_MAX = 2000;
/** Lo mismo que valida el servidor (StoreGenerationRequest::MAX_UPLOAD_KB). */
const UPLOAD_MAX_MB = 15;
const UPLOAD_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

/**
 * Una imagen de entrada en la barra. Se mandan en este orden: una imagen de la
 * galería ("Editar esta imagen"), las ya subidas (otra edición con las mismas)
 * y las fotos nuevas. La primera es la que se edita.
 */
type Reference =
    | { kind: 'image'; id: string; thumb: string }
    | { kind: 'attachment'; id: string; thumb: string }
    | { kind: 'file'; file: File; thumb: string };

const ORDER: Record<Reference['kind'], number> = {
    image: 0,
    attachment: 1,
    file: 2,
};

const textModels = computed(() => props.models.filter((m) => !m.max_images));
const editModels = computed(() => props.models.filter((m) => m.max_images > 0));

const form = useForm({
    prompt: '',
    aspect: '1:1' as string,
    seed: '' as string | number,
    model: (textModels.value[0] ?? props.models[0])?.key ?? '',
    steps: '' as string | number,
    width: 1024 as string | number,
    height: 1024 as string | number,
});

const textarea = ref<HTMLTextAreaElement | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
const showOptions = ref(false);
const references = ref<Reference[]>([]);

const currentAspect = computed(() =>
    props.aspects.find((aspect) => aspect.key === form.aspect),
);
const currentModel = computed(
    () => props.models.find((m) => m.key === form.model) ?? props.models[0],
);
const editing = computed(() => (currentModel.value?.max_images ?? 0) > 0);
const maxReferences = computed(() => editModels.value[0]?.max_images ?? 0);
const missingReference = computed(
    () => references.value.length < (currentModel.value?.min_images ?? 0),
);
const outOfQuota = computed(() => props.quota.remaining === 0);
const canSubmit = computed(
    () =>
        form.prompt.trim().length > 0 &&
        form.prompt.length <= PROMPT_MAX &&
        !missingReference.value &&
        !form.processing &&
        !outOfQuota.value,
);
const placeholder = computed(() =>
    editing.value
        ? references.value.length > 1
            ? // Sin "conservando…", el modelo pega a la persona entera de la foto.
              'Ej.: cambiá la cara de la mujer por la de la imagen 2, conservando su ropa y su postura'
            : 'Ej.: cambiá el texto del cartel por «ABIERTO»'
        : 'Describí la imagen que querés crear…',
);

// La sugerencia larga ocupa más de una línea: el cuadro crece para mostrarla.
watch(placeholder, () => nextTick(autosize));

// Con imágenes va el modelo de edición; sin ellas, el de texto a imagen.
watch(
    () => references.value.length,
    (count) => {
        if (count > 0 && !editing.value && editModels.value[0]) {
            form.model = editModels.value[0].key;
        } else if (count === 0 && editing.value && textModels.value[0]) {
            form.model = textModels.value[0].key;
        }
    },
);

// Elegir a mano un modelo de texto a imagen deja las imágenes sin uso.
watch(
    () => form.model,
    () => {
        if (!editing.value && references.value.length) {
            clearReferences();
            toast.info('Se quitaron las imágenes: ese modelo no las usa.');
        }
    },
);

function addReference(reference: Reference): void {
    if (references.value.length >= maxReferences.value) {
        toast.error(`Podés usar hasta ${maxReferences.value} imágenes.`);

        return;
    }

    references.value = [...references.value, reference].sort(
        (a, b) => ORDER[a.kind] - ORDER[b.kind],
    );
}

function removeReference(index: number): void {
    const [removed] = references.value.splice(index, 1);

    if (removed?.kind === 'file') {
        URL.revokeObjectURL(removed.thumb);
    }
}

function clearReferences(): void {
    references.value.forEach((r) => {
        if (r.kind === 'file') {
            URL.revokeObjectURL(r.thumb);
        }
    });
    references.value = [];
}

onBeforeUnmount(clearReferences);

function pickFiles(): void {
    fileInput.value?.click();
}

function onFiles(event: Event): void {
    const input = event.target as HTMLInputElement;

    for (const file of Array.from(input.files ?? [])) {
        if (!UPLOAD_TYPES.includes(file.type)) {
            toast.error(`«${file.name}» no es una imagen JPG, PNG o WebP.`);
            continue;
        }

        if (file.size > UPLOAD_MAX_MB * 1024 * 1024) {
            toast.error(`«${file.name}» pesa más de ${UPLOAD_MAX_MB} MB.`);
            continue;
        }

        addReference({ kind: 'file', file, thumb: URL.createObjectURL(file) });
    }

    // Para poder elegir el mismo archivo otra vez después de quitarlo.
    input.value = '';
    nextTick(() => textarea.value?.focus());
}

function focusEnd(): void {
    nextTick(() => {
        autosize();
        textarea.value?.focus();
        textarea.value?.setSelectionRange(
            form.prompt.length,
            form.prompt.length,
        );
    });
}

/** "Editar esta imagen": una imagen propia de la galería pasa a ser la imagen 1. */
function editImage(image: ReferenceThumb): void {
    references.value = references.value.filter((r) => r.kind !== 'image');
    addReference({ kind: 'image', id: image.id, thumb: image.thumb_url });
    focusEnd();
}
const hasFixedSeed = computed(() => String(form.seed).trim() !== '');
const optionsCount = computed(
    () =>
        Number(hasFixedSeed.value) +
        Number(String(form.steps).trim() !== '') +
        Number(form.aspect === 'custom' && !editing.value),
);

const quotaText = computed(() => {
    const { limit, remaining } = props.quota;

    if (limit === null || remaining === null) {
        return null;
    }

    if (remaining === 0) {
        return 'Llegaste al límite de hoy';
    }

    return `Te ${remaining === 1 ? 'queda' : 'quedan'} ${remaining} de ${limit} hoy`;
});

function autosize(): void {
    const el = textarea.value;

    if (!el) {
        return;
    }

    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 220)}px`;
}

watch(
    () => form.prompt,
    () => nextTick(autosize),
);

function onKeydown(event: KeyboardEvent): void {
    // Enter genera; Shift+Enter (o el teclado del celular) agrega un salto.
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        const coarse = window.matchMedia('(pointer: coarse)').matches;

        if (!coarse) {
            event.preventDefault();
            submit();
        }
    }
}

function randomSeed(): void {
    form.seed = Math.floor(Math.random() * 2 ** 32);
    showOptions.value = true;
}

function submit(): void {
    if (!canSubmit.value) {
        return;
    }

    const refs = references.value;
    // Editando, la salida toma la proporción de la imagen 1: no va aspecto.
    const aspect = editing.value ? null : form.aspect;

    form.transform((data) => ({
        prompt: data.prompt,
        model: data.model || null,
        aspect,
        seed: String(data.seed).trim() === '' ? null : Number(data.seed),
        steps:
            props.isAdmin && String(data.steps).trim() !== ''
                ? Number(data.steps)
                : null,
        width: aspect === 'custom' ? Number(data.width) : null,
        height: aspect === 'custom' ? Number(data.height) : null,
        image_ids: refs.flatMap((r) => (r.kind === 'image' ? [r.id] : [])),
        attachment_ids: refs.flatMap((r) =>
            r.kind === 'attachment' ? [r.id] : [],
        ),
        // Con un archivo adentro, Inertia lo manda como multipart.
        images: refs.flatMap((r) => (r.kind === 'file' ? [r.file] : [])),
        conversation_id: props.conversationId,
    })).post(store().url, {
        preserveScroll: true,
        // En una conversación existente alcanza con recargar el hilo. Una nueva
        // cambia de URL y suma una entrada al menú: va la visita completa.
        ...(props.conversationId
            ? { only: ['generations', 'quota', 'flash'], preserveState: true }
            : {}),
        onSuccess: () => {
            // Como en un chat: el pedido pasa al hilo y la caja queda libre.
            form.prompt = '';
            clearReferences();
            nextTick(autosize);
            emit('submitted');
        },
    });
}

/** Carga un prompt (por ejemplo, "Reusar prompt" desde el visor). */
function fill(preset: PromptPreset): void {
    form.prompt = preset.prompt;

    if (preset.aspect && props.aspects.some((a) => a.key === preset.aspect)) {
        form.aspect = preset.aspect;
    }

    form.seed = preset.seed ?? '';

    if (preset.seed != null) {
        showOptions.value = true;
    }

    // Una edición vuelve con sus mismas imágenes; un prompt de texto, sin ninguna.
    clearReferences();
    (preset.attachments ?? []).forEach((a) =>
        addReference({ kind: 'attachment', id: a.id, thumb: a.thumb_url }),
    );

    if (preset.model && props.models.some((m) => m.key === preset.model)) {
        form.model = preset.model;
    }

    focusEnd();
}

defineExpose({ fill, editImage });
</script>

<template>
    <form
        class="mx-auto w-full max-w-3xl rounded-2xl border bg-background/95 shadow-lg backdrop-blur supports-[backdrop-filter]:bg-background/80"
        data-test="prompt-bar"
        @submit.prevent="submit"
    >
        <ul
            v-if="references.length"
            class="flex flex-wrap gap-2 px-3 pt-3"
            aria-label="Imágenes para editar"
            data-test="references"
        >
            <li
                v-for="(reference, index) in references"
                :key="reference.thumb"
                class="relative"
            >
                <img
                    :src="reference.thumb"
                    :alt="`Imagen ${index + 1}`"
                    class="size-16 rounded-lg border object-cover"
                />
                <span
                    class="absolute bottom-1 left-1 rounded bg-black/60 px-1 text-[10px] leading-4 font-medium text-white"
                    >{{ index + 1 }}</span
                >
                <button
                    type="button"
                    class="absolute -top-1.5 -right-1.5 flex size-6 items-center justify-center rounded-full border bg-background shadow-sm hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :aria-label="`Quitar la imagen ${index + 1}`"
                    @click="removeReference(index)"
                >
                    <X class="size-3.5" />
                </button>
            </li>
        </ul>

        <label for="prompt" class="sr-only">{{
            editing ? 'Qué querés cambiar' : 'Describí la imagen'
        }}</label>
        <textarea
            id="prompt"
            ref="textarea"
            v-model="form.prompt"
            name="prompt"
            rows="1"
            :maxlength="PROMPT_MAX"
            :placeholder="placeholder"
            class="block max-h-[220px] min-h-12 w-full resize-none bg-transparent px-4 pt-3.5 pb-1 text-base outline-none placeholder:text-muted-foreground"
            enterkeyhint="send"
            @keydown="onKeydown"
            @input="autosize"
        />

        <div
            v-if="showOptions"
            class="grid gap-3 border-t border-dashed px-4 py-3 text-sm sm:grid-cols-2"
            data-test="prompt-options"
        >
            <div class="grid gap-1.5">
                <Label for="seed">Semilla</Label>
                <div class="flex gap-1.5">
                    <Input
                        id="seed"
                        v-model="form.seed"
                        type="number"
                        inputmode="numeric"
                        min="0"
                        placeholder="Aleatoria"
                        class="h-8"
                    />
                    <Button
                        type="button"
                        variant="outline"
                        size="icon-sm"
                        aria-label="Semilla al azar"
                        @click="randomSeed"
                    >
                        <Dices />
                    </Button>
                    <Button
                        v-if="hasFixedSeed"
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        aria-label="Volver a semilla aleatoria"
                        @click="form.seed = ''"
                    >
                        <X />
                    </Button>
                </div>
                <p class="text-xs text-muted-foreground">
                    Con la misma semilla y el mismo prompt sale la misma imagen.
                </p>
            </div>

            <div v-if="models.length > 1" class="grid gap-1.5">
                <Label for="model">Modelo</Label>
                <select
                    id="model"
                    v-model="form.model"
                    class="h-8 rounded-md border bg-transparent px-2"
                >
                    <option v-for="m in models" :key="m.key" :value="m.key">
                        {{ m.label }}
                    </option>
                </select>
            </div>

            <template v-if="isAdmin">
                <div class="grid gap-1.5">
                    <Label for="steps">Pasos (admin)</Label>
                    <Input
                        id="steps"
                        v-model="form.steps"
                        type="number"
                        min="1"
                        :max="currentModel?.max_steps"
                        :placeholder="`${currentModel?.default_steps ?? ''} (por defecto)`"
                        class="h-8"
                    />
                </div>
                <div
                    v-if="form.aspect === 'custom' && !editing"
                    class="grid gap-1.5"
                >
                    <Label>Tamaño libre (múltiplos de 16)</Label>
                    <div class="flex items-center gap-1.5">
                        <Input
                            v-model="form.width"
                            type="number"
                            min="256"
                            :max="maxSide"
                            step="16"
                            aria-label="Ancho"
                            class="h-8"
                        />
                        <span class="text-muted-foreground">×</span>
                        <Input
                            v-model="form.height"
                            type="number"
                            min="256"
                            :max="maxSide"
                            step="16"
                            aria-label="Alto"
                            class="h-8"
                        />
                    </div>
                </div>
            </template>
        </div>

        <p
            v-if="Object.keys(form.errors).length"
            class="px-4 pt-1 text-xs text-destructive"
            role="alert"
        >
            {{ Object.values(form.errors)[0] }}
        </p>

        <p
            v-if="editing && missingReference"
            class="px-4 pt-1 text-xs text-muted-foreground"
        >
            Adjuntá la imagen que querés editar.
        </p>

        <div class="flex items-center gap-1.5 px-2 pt-1 pb-2">
            <template v-if="editModels.length">
                <input
                    ref="fileInput"
                    type="file"
                    :accept="UPLOAD_TYPES.join(',')"
                    multiple
                    class="sr-only"
                    tabindex="-1"
                    aria-hidden="true"
                    data-test="file-input"
                    @change="onFiles"
                />
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="gap-1.5 rounded-full px-2.5"
                    :disabled="references.length >= maxReferences"
                    data-test="attach"
                    @click="pickFiles"
                >
                    <ImagePlus />
                    <span class="sr-only sm:not-sr-only">Imagen</span>
                </Button>
            </template>

            <span
                v-if="editing"
                class="inline-flex h-8 min-w-0 items-center gap-1.5 rounded-full px-2 text-sm text-muted-foreground"
                data-test="edit-mode"
                :title="currentModel?.description ?? undefined"
            >
                <Wand class="size-4 shrink-0" />
                <span class="truncate">{{ currentModel?.label }}</span>
            </span>

            <DropdownMenu v-else>
                <DropdownMenuTrigger as-child>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="gap-1.5 rounded-full px-2.5"
                        data-test="aspect"
                        :aria-label="`Relación de aspecto: ${form.aspect === 'custom' ? 'libre' : form.aspect}`"
                    >
                        <AspectIcon
                            v-if="currentAspect"
                            :width="currentAspect.width"
                            :height="currentAspect.height"
                        />
                        <AspectIcon
                            v-else
                            :width="Number(form.width) || 1"
                            :height="Number(form.height) || 1"
                        />
                        {{ form.aspect === 'custom' ? 'Libre' : form.aspect }}
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" class="w-52">
                    <DropdownMenuLabel>Relación de aspecto</DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    <DropdownMenuRadioGroup v-model="form.aspect">
                        <DropdownMenuRadioItem
                            v-for="aspect in aspects"
                            :key="aspect.key"
                            :value="aspect.key"
                        >
                            <AspectIcon
                                :width="aspect.width"
                                :height="aspect.height"
                            />
                            <span class="flex-1">{{ aspect.key }}</span>
                            <span class="text-xs text-muted-foreground">
                                {{ aspect.width }}×{{ aspect.height }}
                            </span>
                        </DropdownMenuRadioItem>
                        <DropdownMenuRadioItem
                            v-if="isAdmin"
                            value="custom"
                            @select="showOptions = true"
                        >
                            <SlidersHorizontal />
                            <span class="flex-1">Libre</span>
                            <span class="text-xs text-muted-foreground"
                                >admin</span
                            >
                        </DropdownMenuRadioItem>
                    </DropdownMenuRadioGroup>
                </DropdownMenuContent>
            </DropdownMenu>

            <Button
                type="button"
                variant="ghost"
                size="sm"
                class="relative gap-1.5 rounded-full px-2.5"
                :aria-expanded="showOptions"
                data-test="toggle-options"
                @click="showOptions = !showOptions"
            >
                <SlidersHorizontal />
                <span class="hidden sm:inline">Opciones</span>
                <span
                    v-if="optionsCount"
                    class="rounded-full bg-primary px-1.5 text-[10px] leading-4 text-primary-foreground"
                    >{{ optionsCount }}</span
                >
            </Button>

            <span
                v-if="quotaText"
                class="ml-auto truncate px-1 text-xs"
                :class="
                    outOfQuota ? 'text-destructive' : 'text-muted-foreground'
                "
                data-test="quota"
            >
                {{ quotaText }}
            </span>

            <Button
                type="submit"
                size="icon"
                class="shrink-0 rounded-full"
                :class="{ 'ml-auto': !quotaText }"
                :disabled="!canSubmit"
                aria-label="Generar"
                data-test="generate"
            >
                <Spinner v-if="form.processing" />
                <ArrowUp v-else />
            </Button>
        </div>
    </form>
</template>

<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ArrowUp, Dices, SlidersHorizontal, X } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
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
import type { AspectOption, ModelOption, PromptPreset, Quota } from '@/types';

const props = defineProps<{
    aspects: AspectOption[];
    models: ModelOption[];
    quota: Quota;
    isAdmin: boolean;
    maxSide: number;
}>();

const emit = defineEmits<{ submitted: [] }>();

const PROMPT_MAX = 2000;

const form = useForm({
    prompt: '',
    aspect: '1:1' as string,
    seed: '' as string | number,
    model: props.models[0]?.key ?? '',
    steps: '' as string | number,
    width: 1024 as string | number,
    height: 1024 as string | number,
});

const textarea = ref<HTMLTextAreaElement | null>(null);
const showOptions = ref(false);

const currentAspect = computed(() =>
    props.aspects.find((aspect) => aspect.key === form.aspect),
);
const currentModel = computed(
    () => props.models.find((m) => m.key === form.model) ?? props.models[0],
);
const outOfQuota = computed(() => props.quota.remaining === 0);
const canSubmit = computed(
    () =>
        form.prompt.trim().length > 0 &&
        form.prompt.length <= PROMPT_MAX &&
        !form.processing &&
        !outOfQuota.value,
);
const hasFixedSeed = computed(() => String(form.seed).trim() !== '');
const optionsCount = computed(
    () =>
        Number(hasFixedSeed.value) +
        Number(String(form.steps).trim() !== '') +
        Number(form.aspect === 'custom'),
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

    form.transform((data) => ({
        prompt: data.prompt,
        model: data.model || null,
        aspect: data.aspect,
        seed: String(data.seed).trim() === '' ? null : Number(data.seed),
        steps:
            props.isAdmin && String(data.steps).trim() !== ''
                ? Number(data.steps)
                : null,
        width: data.aspect === 'custom' ? Number(data.width) : null,
        height: data.aspect === 'custom' ? Number(data.height) : null,
    })).post(store().url, {
        preserveScroll: true,
        only: ['generations', 'quota', 'has_more', 'flash'],
        onSuccess: () => emit('submitted'),
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

    nextTick(() => {
        autosize();
        textarea.value?.focus();
        textarea.value?.setSelectionRange(
            form.prompt.length,
            form.prompt.length,
        );
    });
}

defineExpose({ fill });
</script>

<template>
    <form
        class="mx-auto w-full max-w-3xl rounded-2xl border bg-background/95 shadow-lg backdrop-blur supports-[backdrop-filter]:bg-background/80"
        data-test="prompt-bar"
        @submit.prevent="submit"
    >
        <label for="prompt" class="sr-only">Describí la imagen</label>
        <textarea
            id="prompt"
            ref="textarea"
            v-model="form.prompt"
            name="prompt"
            rows="1"
            :maxlength="PROMPT_MAX"
            placeholder="Describí la imagen que querés crear…"
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
                <div v-if="form.aspect === 'custom'" class="grid gap-1.5">
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

        <div class="flex items-center gap-1.5 px-2 pt-1 pb-2">
            <DropdownMenu>
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

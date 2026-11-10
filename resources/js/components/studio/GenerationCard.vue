<script setup lang="ts">
import { AlertTriangle, Heart, RotateCcw, X } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import type { GenerationItem } from '@/types';

const props = defineProps<{ generation: GenerationItem }>();

defineEmits<{ open: []; retry: []; dismiss: [] }>();

const pending = computed(() =>
    ['queued', 'waiting_gpu', 'running'].includes(props.generation.status),
);

const percent = computed(() =>
    props.generation.status === 'running' && props.generation.progress != null
        ? Math.round(Math.min(1, Math.max(0, props.generation.progress)) * 100)
        : null,
);

const pendingText = computed(() => {
    const g = props.generation;

    if (g.status === 'waiting_gpu') {
        return 'Esperando que se prenda la placa';
    }

    if (g.status === 'queued' && g.queue_position) {
        return `En cola · ${g.queue_position}º`;
    }

    if (g.status === 'running') {
        return percent.value != null
            ? `Generando · ${percent.value}%`
            : 'Generando';
    }

    return 'En cola';
});
</script>

<template>
    <div
        class="group relative mb-3 break-inside-avoid overflow-hidden rounded-xl border bg-muted/40"
        :style="{ aspectRatio: `${generation.width} / ${generation.height}` }"
        :class="!generation.image && 'min-h-44'"
        :data-status="generation.status"
        data-test="generation"
    >
        <!-- Terminada -->
        <button
            v-if="generation.image"
            type="button"
            class="block size-full focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            :aria-label="`Ver imagen: ${generation.prompt}`"
            @click="$emit('open')"
        >
            <img
                :src="generation.image.thumb_url"
                :alt="generation.prompt"
                loading="lazy"
                class="size-full object-cover transition-transform duration-300 group-hover:scale-[1.02]"
            />
            <span
                class="pointer-events-none absolute inset-x-0 bottom-0 line-clamp-2 bg-gradient-to-t from-black/70 to-transparent px-3 pt-6 pb-2 text-left text-xs text-white opacity-0 transition-opacity group-hover:opacity-100"
            >
                {{ generation.prompt }}
            </span>
            <Heart
                v-if="generation.image.favorite"
                class="absolute top-2 right-2 size-4 fill-rose-500 text-rose-500 drop-shadow"
            />
        </button>

        <!-- Pendiente -->
        <div
            v-else-if="pending"
            class="flex size-full flex-col items-center justify-center gap-3 p-4 text-center"
        >
            <div
                class="absolute inset-0 animate-pulse bg-gradient-to-br from-violet-500/10 via-transparent to-sky-500/10"
            />
            <div
                class="relative size-8 rounded-full border-2 border-muted-foreground/20 border-t-primary"
                :class="
                    generation.status === 'waiting_gpu' ? '' : 'animate-spin'
                "
            />
            <p class="relative text-xs font-medium" data-test="pending-text">
                {{ pendingText }}
            </p>
            <p
                class="relative line-clamp-2 max-w-[90%] text-xs text-muted-foreground"
            >
                {{ generation.prompt }}
            </p>
            <div
                v-if="percent != null"
                class="absolute inset-x-0 bottom-0 h-1 bg-muted"
            >
                <div
                    class="h-full bg-primary transition-[width] duration-700"
                    :style="{ width: `${percent}%` }"
                />
            </div>
        </div>

        <!-- Falló -->
        <div
            v-else
            class="flex size-full flex-col items-center justify-center gap-2 p-4 text-center"
        >
            <AlertTriangle class="size-5 text-destructive" />
            <p class="text-xs font-medium">No se pudo generar</p>
            <p
                class="line-clamp-3 max-w-[95%] text-xs text-muted-foreground"
                :title="generation.error ?? ''"
            >
                {{ generation.error }}
            </p>
            <div class="flex gap-1.5">
                <Button size="sm" variant="outline" @click="$emit('retry')">
                    <RotateCcw />
                    Reintentar
                </Button>
                <Button
                    size="icon-sm"
                    variant="ghost"
                    aria-label="Descartar"
                    @click="$emit('dismiss')"
                >
                    <X />
                </Button>
            </div>
        </div>
    </div>
</template>

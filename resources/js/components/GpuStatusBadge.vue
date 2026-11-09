<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Cpu } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { status as gpuStatus } from '@/routes/gpu';

type GpuState = 'available' | 'busy' | 'offline' | 'error';

type GpuStatus = {
    state: GpuState;
    online: boolean;
    message: string;
    queue_pending: number;
    avg_seconds: number | null;
    checked_at: string | null;
    my_pending: number;
    // Solo para el admin:
    latency_ms?: number | null;
    device?: string | null;
    vram_free_gb?: number | null;
    vram_total_gb?: number | null;
};

const POLL_MS = 15_000;

const page = usePage();
const enabled = computed(() => page.props.auth.is_approved);
const status = ref<GpuStatus | null>(null);
let timer: ReturnType<typeof setInterval> | undefined;
let controller: AbortController | undefined;

const labels: Record<GpuState, string> = {
    available: 'Placa disponible',
    busy: 'Placa ocupada',
    offline: 'Placa apagada',
    error: 'Placa con error',
};

const dotClass: Record<GpuState, string> = {
    available: 'bg-emerald-500',
    busy: 'bg-amber-500',
    offline: 'bg-zinc-400 dark:bg-zinc-500',
    error: 'bg-red-500',
};

const label = computed(() => {
    if (!status.value) {
        return 'Consultando…';
    }

    if (status.value.state === 'busy' && status.value.queue_pending > 0) {
        return `Ocupada · ${status.value.queue_pending} en cola`;
    }

    return labels[status.value.state];
});

const checkedAgo = computed(() => {
    if (!status.value?.checked_at) {
        return null;
    }

    const seconds = Math.max(
        0,
        Math.round((Date.now() - Date.parse(status.value.checked_at)) / 1000),
    );

    return seconds < 60
        ? `hace ${seconds} s`
        : `hace ${Math.round(seconds / 60)} min`;
});

async function refresh(): Promise<void> {
    if (!enabled.value || document.visibilityState === 'hidden') {
        return;
    }

    controller?.abort();
    controller = new AbortController();

    try {
        const response = await fetch(gpuStatus().url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        });

        if (response.ok) {
            status.value = (await response.json()) as GpuStatus;
        }
    } catch {
        // Sin conexión o pedido cancelado: se mantiene lo último que se vio.
    }
}

function onVisibility(): void {
    if (document.visibilityState === 'visible') {
        void refresh();
    }
}

onMounted(() => {
    void refresh();
    timer = setInterval(refresh, POLL_MS);
    document.addEventListener('visibilitychange', onVisibility);
});

onBeforeUnmount(() => {
    clearInterval(timer);
    controller?.abort();
    document.removeEventListener('visibilitychange', onVisibility);
});
</script>

<template>
    <DropdownMenu v-if="enabled">
        <DropdownMenuTrigger
            class="inline-flex h-8 shrink-0 items-center gap-2 rounded-full border border-sidebar-border/70 px-3 text-xs font-medium text-foreground/80 transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            :aria-label="`Estado de la placa: ${label}`"
            data-test="gpu-status"
        >
            <span class="relative flex size-2.5">
                <span
                    v-if="status?.state === 'busy'"
                    class="absolute inline-flex size-full animate-ping rounded-full bg-amber-400 opacity-60"
                />
                <span
                    class="relative inline-flex size-2.5 rounded-full"
                    :class="status ? dotClass[status.state] : 'bg-zinc-300'"
                />
            </span>
            <span class="hidden sm:inline">{{ label }}</span>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="end" class="w-72">
            <DropdownMenuLabel class="flex items-center gap-2">
                <Cpu class="size-4" />
                {{ label }}
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <div class="space-y-1.5 px-2 py-1.5 text-sm">
                <p>{{ status?.message ?? 'Consultando la placa…' }}</p>
                <p v-if="status?.avg_seconds" class="text-muted-foreground">
                    Cada imagen tarda unos
                    {{ Math.round(status.avg_seconds) }} s.
                </p>
                <p v-if="status?.my_pending" class="text-muted-foreground">
                    Tenés {{ status.my_pending }}
                    {{
                        status.my_pending === 1
                            ? 'imagen pendiente'
                            : 'imágenes pendientes'
                    }}.
                </p>
                <p
                    v-if="status && !status.online"
                    class="text-muted-foreground"
                >
                    Podés pedir imágenes igual: se generan cuando la placa
                    vuelva.
                </p>
                <template v-if="status?.device">
                    <DropdownMenuSeparator />
                    <p class="text-xs text-muted-foreground">
                        {{ status.device }}<br />
                        VRAM libre: {{ status.vram_free_gb }} de
                        {{ status.vram_total_gb }} GB
                        <template v-if="status.latency_ms != null">
                            · {{ status.latency_ms }} ms
                        </template>
                    </p>
                </template>
                <p v-if="checkedAgo" class="text-xs text-muted-foreground">
                    Consultado {{ checkedAgo }}.
                </p>
            </div>
        </DropdownMenuContent>
    </DropdownMenu>
</template>

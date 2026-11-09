<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Cpu, HardDrive, PlugZap } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index, testGpu, update } from '@/routes/admin/settings';

type GpuStatus = {
    state: 'available' | 'busy' | 'offline' | 'error';
    message: string;
    latency_ms: number | null;
    device: string | null;
    vram_free_gb: number | null;
    vram_total_gb: number | null;
    checked_at: string | null;
};

const props = defineProps<{
    settings: {
        gpu_url: string | null;
        gpu_url_env: string | null;
        gpu_token_set: boolean;
        gpu_token_env_set: boolean;
        default_daily_quota: number;
        retention_days: number;
    };
    gpu: GpuStatus | null;
    stats: {
        images: number;
        bytes: number;
        pending: number;
        waiting_gpu: number;
        failed_today: number;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Ajustes', href: index() }],
    },
});

const form = useForm({
    gpu_url: props.settings.gpu_url ?? '',
    gpu_token: '',
    clear_gpu_token: false,
    default_daily_quota: props.settings.default_daily_quota,
    retention_days: props.settings.retention_days,
});

const testing = ref(false);

const tokenHint = computed(() => {
    if (props.settings.gpu_token_set) {
        return 'Hay un token guardado acá. Dejalo vacío para no cambiarlo.';
    }

    if (props.settings.gpu_token_env_set) {
        return 'Se usa el token del archivo .env. Escribí uno para reemplazarlo.';
    }

    return 'Todavía no hay token: copiá el de gpu_worker/.env de la PC.';
});

const stateLabel: Record<GpuStatus['state'], string> = {
    available: 'Disponible',
    busy: 'Ocupada',
    offline: 'Apagada',
    error: 'Con error',
};

const stateClass: Record<GpuStatus['state'], string> = {
    available: 'bg-emerald-500',
    busy: 'bg-amber-500',
    offline: 'bg-zinc-400',
    error: 'bg-red-500',
};

function submit(): void {
    form.put(update().url, {
        preserveScroll: true,
        onSuccess: () => form.reset('gpu_token', 'clear_gpu_token'),
    });
}

function runTest(): void {
    router.post(
        testGpu().url,
        {},
        {
            preserveScroll: true,
            onStart: () => (testing.value = true),
            onFinish: () => (testing.value = false),
        },
    );
}

function formatBytes(bytes: number): string {
    if (bytes < 1024 ** 2) {
        return `${Math.round(bytes / 1024)} KB`;
    }

    if (bytes < 1024 ** 3) {
        return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
    }

    return `${(bytes / 1024 ** 3).toFixed(2)} GB`;
}

function formatDate(value: string | null): string {
    return value
        ? new Date(value).toLocaleString('es-AR', {
              dateStyle: 'short',
              timeStyle: 'medium',
          })
        : '—';
}
</script>

<template>
    <Head title="Ajustes" />

    <div class="flex max-w-3xl flex-col gap-8 p-4">
        <Heading
            title="Ajustes"
            description="Conexión con la placa, cuotas y retención de imágenes"
        />

        <section class="grid gap-3 sm:grid-cols-2">
            <div class="flex min-w-0 flex-col gap-2 rounded-xl border p-4">
                <div class="flex items-center gap-2 font-medium">
                    <Cpu class="size-4" />
                    Placa
                    <span
                        v-if="gpu"
                        class="ml-auto inline-flex items-center gap-1.5 text-sm font-normal"
                    >
                        <span
                            class="size-2 rounded-full"
                            :class="stateClass[gpu.state]"
                        />
                        {{ stateLabel[gpu.state] }}
                    </span>
                </div>
                <p class="text-sm text-muted-foreground">
                    {{ gpu?.message ?? 'Todavía no se consultó.' }}
                </p>
                <p
                    v-if="gpu?.device"
                    class="truncate text-xs text-muted-foreground"
                >
                    {{ gpu.device }} · VRAM libre {{ gpu.vram_free_gb }} /
                    {{ gpu.vram_total_gb }} GB
                    <template v-if="gpu.latency_ms != null">
                        · {{ gpu.latency_ms }} ms</template
                    >
                </p>
                <p class="text-xs text-muted-foreground">
                    Última consulta: {{ formatDate(gpu?.checked_at ?? null) }}
                </p>
                <Button
                    variant="outline"
                    size="sm"
                    class="mt-auto self-start"
                    :disabled="testing"
                    data-test="test-gpu"
                    @click="runTest"
                >
                    <PlugZap class="size-4" />
                    {{ testing ? 'Probando…' : 'Probar conexión' }}
                </Button>
            </div>

            <div class="flex min-w-0 flex-col gap-2 rounded-xl border p-4">
                <div class="flex items-center gap-2 font-medium">
                    <HardDrive class="size-4" />
                    Imágenes
                </div>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                    <dt class="text-muted-foreground">Guardadas</dt>
                    <dd>{{ stats.images }}</dd>
                    <dt class="text-muted-foreground">Espacio</dt>
                    <dd>{{ formatBytes(stats.bytes) }}</dd>
                    <dt class="text-muted-foreground">En proceso</dt>
                    <dd>{{ stats.pending }}</dd>
                    <dt class="text-muted-foreground">Esperando placa</dt>
                    <dd>{{ stats.waiting_gpu }}</dd>
                    <dt class="text-muted-foreground">Fallidas (24 h)</dt>
                    <dd>{{ stats.failed_today }}</dd>
                </dl>
            </div>
        </section>

        <form class="flex flex-col gap-8" @submit.prevent="submit">
            <section class="flex flex-col gap-4">
                <Heading
                    variant="small"
                    title="Conexión con la placa"
                    description="La dirección del túnel de Cloudflare y el token del gateway (gpu_worker)"
                />

                <div class="grid gap-2">
                    <Label for="gpu_url">URL del gateway</Label>
                    <Input
                        id="gpu_url"
                        v-model="form.gpu_url"
                        type="url"
                        inputmode="url"
                        autocomplete="off"
                        :placeholder="
                            settings.gpu_url_env ||
                            'https://lumora-gpu.bambudev.com.ar'
                        "
                    />
                    <p class="text-xs text-muted-foreground">
                        Vacío = se usa la del archivo .env<template
                            v-if="settings.gpu_url_env"
                            >&nbsp;({{ settings.gpu_url_env }})</template
                        >.
                    </p>
                    <InputError :message="form.errors.gpu_url" />
                </div>

                <div class="grid gap-2">
                    <Label for="gpu_token">Token</Label>
                    <Input
                        id="gpu_token"
                        v-model="form.gpu_token"
                        type="password"
                        autocomplete="new-password"
                        :disabled="form.clear_gpu_token"
                        :placeholder="
                            settings.gpu_token_set ? '••••••••••••' : ''
                        "
                    />
                    <p class="text-xs text-muted-foreground">
                        {{ tokenHint }}
                    </p>
                    <label
                        v-if="settings.gpu_token_set"
                        class="flex items-center gap-2 text-sm"
                    >
                        <Checkbox v-model="form.clear_gpu_token" />
                        Borrar el token guardado y usar el del .env
                    </label>
                    <InputError :message="form.errors.gpu_token" />
                </div>
            </section>

            <section class="flex flex-col gap-4">
                <Heading
                    variant="small"
                    title="Uso"
                    description="Límites para los usuarios (el admin no tiene límite)"
                />

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="default_daily_quota"
                            >Imágenes por día</Label
                        >
                        <Input
                            id="default_daily_quota"
                            v-model.number="form.default_daily_quota"
                            type="number"
                            min="0"
                            max="10000"
                            required
                        />
                        <p class="text-xs text-muted-foreground">
                            Para quien no tiene una cuota propia. 0 = no pueden
                            generar.
                        </p>
                        <InputError
                            :message="form.errors.default_daily_quota"
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label for="retention_days">Guardar imágenes</Label>
                        <div class="flex items-center gap-2">
                            <Input
                                id="retention_days"
                                v-model.number="form.retention_days"
                                type="number"
                                min="0"
                                max="3650"
                                required
                                class="w-28"
                            />
                            <span class="text-sm text-muted-foreground"
                                >días</span
                            >
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Después se borran, salvo las favoritas. 0 = no
                            borrar nunca.
                        </p>
                        <InputError :message="form.errors.retention_days" />
                    </div>
                </div>
            </section>

            <div>
                <Button :disabled="form.processing" data-test="save-settings">
                    Guardar
                </Button>
            </div>
        </form>
    </div>
</template>

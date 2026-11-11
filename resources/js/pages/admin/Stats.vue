<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { stats } from '@/routes/admin';

type DailyPoint = { date: string; done: number; failed: number; other: number };

type UserRow = {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    requests: number;
    failed: number;
    gpu_seconds: number;
    images: number;
    stored_bytes: number;
    conversations: number;
    last_request_at: string | null;
    last_seen_at: string | null;
    created_at: string | null;
};

const props = defineProps<{
    days: number;
    ranges: number[];
    totals: {
        requests: number;
        done: number;
        failed: number;
        active_users: number;
        gpu_seconds: number;
        avg_seconds: number | null;
        new_users: number;
        pending_users: number;
        stored_images: number;
        stored_bytes: number;
    };
    daily: DailyPoint[];
    users: UserRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Estadísticas', href: stats() }],
    },
});

const number = new Intl.NumberFormat('es-AR');

function duration(seconds: number | null): string {
    if (seconds == null) {
        return '—';
    }

    const s = Math.round(seconds);

    if (s < 60) {
        return `${s} s`;
    }

    const minutes = Math.round(s / 60);

    if (minutes < 60) {
        return `${minutes} min`;
    }

    const h = Math.floor(minutes / 60);
    const m = minutes % 60;

    return m ? `${h} h ${m} min` : `${h} h`;
}

function bytes(value: number): string {
    if (value < 1024 * 1024) {
        return `${Math.round(value / 1024)} KB`;
    }

    if (value < 1024 ** 3) {
        return `${(value / 1024 ** 2).toLocaleString('es-AR', { maximumFractionDigits: 1 })} MB`;
    }

    return `${(value / 1024 ** 3).toLocaleString('es-AR', { maximumFractionDigits: 2 })} GB`;
}

const relative = new Intl.RelativeTimeFormat('es-AR', { numeric: 'auto' });

function ago(value: string | null): string {
    if (!value) {
        return 'nunca';
    }

    const diff = (new Date(value).getTime() - Date.now()) / 1000;
    const steps: [Intl.RelativeTimeFormatUnit, number][] = [
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];

    for (const [unit, size] of steps) {
        if (Math.abs(diff) >= size) {
            return relative.format(Math.round(diff / size), unit);
        }
    }

    return 'recién';
}

function dayLabel(date: string, long = false): string {
    // La fecha viene como YYYY-MM-DD en hora argentina: se arma al mediodía
    // para que ninguna zona horaria la corra de día.
    return new Date(`${date}T12:00:00`).toLocaleDateString('es-AR', {
        weekday: long ? 'long' : undefined,
        day: 'numeric',
        month: 'short',
    });
}

const failRate = computed(() =>
    props.totals.done + props.totals.failed
        ? Math.round(
              (props.totals.failed /
                  (props.totals.done + props.totals.failed)) *
                  100,
          )
        : 0,
);

const tiles = computed(() => [
    {
        label: 'Pedidos',
        value: number.format(props.totals.requests),
        hint: `${number.format(props.totals.done)} listos · ${failRate.value}% fallaron`,
    },
    {
        label: 'Usuarios activos',
        value: number.format(props.totals.active_users),
        hint: 'con al menos un pedido',
    },
    {
        label: 'Tiempo de placa',
        value: duration(props.totals.gpu_seconds),
        hint: `${duration(props.totals.avg_seconds)} por imagen`,
    },
    {
        label: 'Cuentas nuevas',
        value: number.format(props.totals.new_users),
        hint: props.totals.pending_users
            ? `${props.totals.pending_users} esperando aprobación`
            : 'ninguna pendiente',
    },
    {
        label: 'Imágenes guardadas',
        value: number.format(props.totals.stored_images),
        hint: `${bytes(props.totals.stored_bytes)} en disco · en total`,
    },
]);

// Gráfico: un solo dato por día (los pedidos), así que no lleva leyenda.
const totalOf = (p: DailyPoint) => p.done + p.failed + p.other;
const maxDay = computed(() => Math.max(1, ...props.daily.map(totalOf)));
const hovered = ref<number | null>(null);
const hoveredPoint = computed(() =>
    hovered.value == null ? null : props.daily[hovered.value],
);
const axisDates = computed(() => {
    const d = props.daily;

    return d.length ? [d[0], d[Math.floor(d.length / 2)], d[d.length - 1]] : [];
});
</script>

<template>
    <Head title="Estadísticas" />

    <div class="flex flex-col gap-6 p-4">
        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
        >
            <Heading
                title="Estadísticas"
                :description="`Uso de los últimos ${days} días.`"
            />
            <div class="flex gap-2" role="group" aria-label="Período">
                <Button
                    v-for="range in ranges"
                    :key="range"
                    size="sm"
                    :variant="range === days ? 'default' : 'outline'"
                    as-child
                >
                    <Link
                        :href="stats({ query: { dias: range } })"
                        preserve-scroll
                        :aria-current="range === days ? 'true' : undefined"
                        >{{ range }} días</Link
                    >
                </Button>
            </div>
        </div>

        <ul class="grid grid-cols-2 gap-3 lg:grid-cols-5" data-test="tiles">
            <li
                v-for="tile in tiles"
                :key="tile.label"
                class="flex min-w-0 flex-col gap-1 rounded-xl border p-4 last:col-span-2 lg:last:col-span-1"
            >
                <span class="text-xs text-muted-foreground">{{
                    tile.label
                }}</span>
                <span class="text-2xl font-semibold tabular-nums">{{
                    tile.value
                }}</span>
                <span class="text-xs text-muted-foreground">{{
                    tile.hint
                }}</span>
            </li>
        </ul>

        <section class="rounded-xl border p-4" aria-labelledby="daily-title">
            <h2 id="daily-title" class="text-sm font-medium">
                Pedidos por día
            </h2>

            <div class="relative mt-4">
                <!-- Tooltip -->
                <div
                    v-if="hoveredPoint"
                    class="pointer-events-none absolute -top-2 z-10 -translate-x-1/2 -translate-y-full rounded-md border bg-popover px-2.5 py-1.5 text-xs whitespace-nowrap text-popover-foreground shadow-md"
                    :style="{
                        left: `clamp(4rem, ${((hovered! + 0.5) / daily.length) * 100}%, calc(100% - 4rem))`,
                    }"
                >
                    <p class="font-medium first-letter:uppercase">
                        {{ dayLabel(hoveredPoint.date, true) }}
                    </p>
                    <p class="tabular-nums">
                        {{ totalOf(hoveredPoint) }} pedidos
                        <span class="text-muted-foreground"
                            >· {{ hoveredPoint.done }} listos<template
                                v-if="hoveredPoint.failed"
                            >
                                · {{ hoveredPoint.failed }} fallaron</template
                            ></span
                        >
                    </p>
                </div>

                <div class="flex gap-2">
                    <div
                        class="flex h-40 flex-col justify-between text-right text-xs text-muted-foreground tabular-nums"
                        aria-hidden="true"
                    >
                        <span>{{ maxDay }}</span>
                        <span>0</span>
                    </div>
                    <div
                        class="relative flex h-40 flex-1 items-end border-b border-border"
                        :class="daily.length > 45 ? 'gap-px' : 'gap-0.5'"
                        aria-hidden="true"
                        @pointerleave="hovered = null"
                    >
                        <div
                            class="pointer-events-none absolute inset-x-0 top-0 border-t border-dashed border-border/70"
                        />
                        <div
                            v-for="(point, i) in daily"
                            :key="point.date"
                            class="flex h-full min-w-0 flex-1 cursor-default items-end"
                            :class="
                                hovered !== null && hovered !== i
                                    ? 'opacity-50'
                                    : ''
                            "
                            @pointerenter="hovered = i"
                            @pointerdown="hovered = i"
                        >
                            <div
                                class="mx-auto w-full max-w-12 rounded-t-[4px] bg-chart-1"
                                :style="{
                                    height: totalOf(point)
                                        ? `max(2px, ${(totalOf(point) / maxDay) * 100}%)`
                                        : '0',
                                }"
                            />
                        </div>
                    </div>
                </div>
                <div
                    class="mt-1.5 ml-6 flex justify-between text-xs text-muted-foreground"
                    aria-hidden="true"
                >
                    <span v-for="p in axisDates" :key="p.date">{{
                        dayLabel(p.date)
                    }}</span>
                </div>
            </div>

            <table class="sr-only">
                <caption>
                    Pedidos por día
                </caption>
                <thead>
                    <tr>
                        <th scope="col">Día</th>
                        <th scope="col">Pedidos</th>
                        <th scope="col">Listos</th>
                        <th scope="col">Fallaron</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in daily" :key="p.date">
                        <th scope="row">{{ dayLabel(p.date, true) }}</th>
                        <td>{{ totalOf(p) }}</td>
                        <td>{{ p.done }}</td>
                        <td>{{ p.failed }}</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="flex flex-col gap-3" aria-labelledby="users-title">
            <h2 id="users-title" class="text-sm font-medium">
                Uso por usuario
                <span class="font-normal text-muted-foreground"
                    >· pedidos y tiempo de placa en el período; imágenes y
                    espacio en total</span
                >
            </h2>

            <p v-if="!users.length" class="text-sm text-muted-foreground">
                Todavía no hay cuentas aprobadas.
            </p>

            <!-- Tabla en pantallas anchas -->
            <div
                v-if="users.length"
                class="hidden overflow-hidden rounded-xl border md:block"
            >
                <table class="w-full text-sm" data-test="users-table">
                    <thead
                        class="bg-muted/50 text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium">
                                Usuario
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                Pedidos
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                Placa
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                Imágenes
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                Espacio
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                Conversaciones
                            </th>
                            <th scope="col" class="px-3 py-2 font-medium">
                                Último pedido
                            </th>
                            <th scope="col" class="px-3 py-2 font-medium">
                                Última visita
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="user in users" :key="user.id">
                            <td class="max-w-64 px-3 py-2">
                                <p
                                    class="flex items-center gap-1.5 truncate font-medium"
                                >
                                    <ShieldCheck
                                        v-if="user.is_admin"
                                        class="size-3.5 shrink-0 text-primary"
                                        aria-label="Administrador"
                                    />
                                    <span class="truncate">{{
                                        user.name
                                    }}</span>
                                </p>
                                <p
                                    class="truncate text-xs text-muted-foreground"
                                >
                                    {{ user.email }}
                                </p>
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ number.format(user.requests) }}
                                <span
                                    v-if="user.failed"
                                    class="block text-xs text-muted-foreground"
                                    >{{ user.failed }} fallaron</span
                                >
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ duration(user.gpu_seconds) }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ number.format(user.images) }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ bytes(user.stored_bytes) }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ number.format(user.conversations) }}
                            </td>
                            <td
                                class="px-3 py-2 whitespace-nowrap text-muted-foreground"
                            >
                                {{ ago(user.last_request_at) }}
                            </td>
                            <td
                                class="px-3 py-2 whitespace-nowrap text-muted-foreground"
                            >
                                {{ ago(user.last_seen_at) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Tarjetas en el celular -->
            <ul class="grid gap-3 md:hidden">
                <li
                    v-for="user in users"
                    :key="user.id"
                    class="flex min-w-0 flex-col gap-2 rounded-xl border p-4"
                >
                    <div class="min-w-0">
                        <p
                            class="flex items-center gap-1.5 truncate font-medium"
                        >
                            <ShieldCheck
                                v-if="user.is_admin"
                                class="size-4 shrink-0 text-primary"
                                aria-label="Administrador"
                            />
                            <span class="truncate">{{ user.name }}</span>
                        </p>
                        <p class="truncate text-sm text-muted-foreground">
                            {{ user.email }}
                        </p>
                    </div>
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                        <dt class="text-muted-foreground">Pedidos</dt>
                        <dd class="text-right tabular-nums">
                            {{ number.format(user.requests) }}
                            <span
                                v-if="user.failed"
                                class="text-xs text-muted-foreground"
                                >({{ user.failed }} fallaron)</span
                            >
                        </dd>
                        <dt class="text-muted-foreground">Placa</dt>
                        <dd class="text-right tabular-nums">
                            {{ duration(user.gpu_seconds) }}
                        </dd>
                        <dt class="text-muted-foreground">Imágenes</dt>
                        <dd class="text-right tabular-nums">
                            {{ number.format(user.images) }} ·
                            {{ bytes(user.stored_bytes) }}
                        </dd>
                        <dt class="text-muted-foreground">Conversaciones</dt>
                        <dd class="text-right tabular-nums">
                            {{ number.format(user.conversations) }}
                        </dd>
                        <dt class="text-muted-foreground">Último pedido</dt>
                        <dd class="text-right">
                            {{ ago(user.last_request_at) }}
                        </dd>
                        <dt class="text-muted-foreground">Última visita</dt>
                        <dd class="text-right">
                            {{ ago(user.last_seen_at) }}
                        </dd>
                    </dl>
                </li>
            </ul>
        </section>
    </div>
</template>

<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Check, Search, ShieldCheck, X } from '@lucide/vue';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { approve, index, quota, reject, role } from '@/routes/admin/users';

type AdminUser = {
    id: number;
    name: string;
    email: string;
    email_verified: boolean;
    role: 'admin' | 'user';
    approval_status: 'pending' | 'approved' | 'rejected';
    approved_at: string | null;
    daily_quota: number | null;
    created_at: string | null;
    is_self: boolean;
};

const props = defineProps<{
    users: {
        data: AdminUser[];
        prev_page_url: string | null;
        next_page_url: string | null;
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: { status: string; q: string };
    pendingCount: number;
    defaultDailyQuota: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Usuarios', href: index() }],
    },
});

const statusLabels: Record<AdminUser['approval_status'], string> = {
    pending: 'Pendiente',
    approved: 'Aprobado',
    rejected: 'Rechazado',
};

const statusVariant: Record<
    AdminUser['approval_status'],
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    pending: 'default',
    approved: 'secondary',
    rejected: 'destructive',
};

const tabs = [
    { value: '', label: 'Todos' },
    { value: 'pending', label: 'Pendientes' },
    { value: 'approved', label: 'Aprobados' },
    { value: 'rejected', label: 'Rechazados' },
];

const search = ref(props.filters.q);
let searchTimer: ReturnType<typeof setTimeout> | undefined;

function applyFilters(status = props.filters.status) {
    router.get(
        index().url,
        { status: status || undefined, q: search.value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => applyFilters(), 350);
});

const quotas = ref<Record<number, string>>({});

function quotaValue(user: AdminUser): string {
    return quotas.value[user.id] ?? (user.daily_quota?.toString() || '');
}

function saveQuota(user: AdminUser) {
    const raw = quotaValue(user).trim();
    router.patch(
        quota(user.id).url,
        { daily_quota: raw === '' ? null : Number(raw) },
        { preserveScroll: true },
    );
}

function setRole(user: AdminUser, newRole: AdminUser['role']) {
    router.patch(
        role(user.id).url,
        { role: newRole },
        { preserveScroll: true },
    );
}

function formatDate(value: string | null): string {
    return value
        ? new Date(value).toLocaleDateString('es-AR', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : '—';
}
</script>

<template>
    <Head title="Usuarios" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Usuarios"
            :description="
                pendingCount > 0
                    ? `Hay ${pendingCount} ${pendingCount === 1 ? 'cuenta esperando' : 'cuentas esperando'} aprobación.`
                    : 'No hay cuentas esperando aprobación.'
            "
        />

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="flex flex-wrap gap-2">
                <Button
                    v-for="tab in tabs"
                    :key="tab.value"
                    size="sm"
                    :variant="
                        filters.status === tab.value ? 'default' : 'outline'
                    "
                    @click="applyFilters(tab.value)"
                >
                    {{ tab.label }}
                    <span
                        v-if="tab.value === 'pending' && pendingCount > 0"
                        class="ml-1 rounded-full bg-primary-foreground/20 px-1.5 text-xs"
                        >{{ pendingCount }}</span
                    >
                </Button>
            </div>
            <div class="relative w-full sm:ml-auto sm:w-64">
                <Search
                    class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="search"
                    class="pl-8"
                    placeholder="Buscar por nombre o email"
                    aria-label="Buscar usuarios"
                />
            </div>
        </div>

        <p v-if="users.data.length === 0" class="text-sm text-muted-foreground">
            No se encontraron usuarios.
        </p>

        <ul class="grid gap-3 lg:grid-cols-2">
            <li
                v-for="user in users.data"
                :key="user.id"
                class="flex min-w-0 flex-col gap-3 rounded-xl border p-4"
            >
                <div class="flex min-w-0 items-start gap-3">
                    <div class="min-w-0 flex-1">
                        <p
                            class="flex items-center gap-1.5 truncate font-medium"
                        >
                            <ShieldCheck
                                v-if="user.role === 'admin'"
                                class="size-4 shrink-0 text-primary"
                                aria-label="Administrador"
                            />
                            <span class="truncate">{{ user.name }}</span>
                            <span
                                v-if="user.is_self"
                                class="text-xs text-muted-foreground"
                                >(vos)</span
                            >
                        </p>
                        <p class="truncate text-sm text-muted-foreground">
                            {{ user.email }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Registrado el {{ formatDate(user.created_at) }}
                            <span v-if="!user.email_verified">
                                · email sin verificar</span
                            >
                        </p>
                    </div>
                    <Badge :variant="statusVariant[user.approval_status]">
                        {{ statusLabels[user.approval_status] }}
                    </Badge>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        v-if="user.approval_status !== 'approved'"
                        size="sm"
                        as-child
                    >
                        <Link
                            :href="approve(user.id)"
                            as="button"
                            preserve-scroll
                        >
                            <Check /> Aprobar
                        </Link>
                    </Button>
                    <Button
                        v-if="
                            user.approval_status !== 'rejected' && !user.is_self
                        "
                        size="sm"
                        variant="outline"
                        as-child
                    >
                        <Link
                            :href="reject(user.id)"
                            as="button"
                            preserve-scroll
                        >
                            <X /> Rechazar
                        </Link>
                    </Button>
                    <Button
                        v-if="!user.is_self"
                        size="sm"
                        variant="ghost"
                        @click="
                            setRole(
                                user,
                                user.role === 'admin' ? 'user' : 'admin',
                            )
                        "
                    >
                        {{
                            user.role === 'admin'
                                ? 'Quitar admin'
                                : 'Hacer admin'
                        }}
                    </Button>
                </div>

                <form
                    v-if="user.role !== 'admin'"
                    class="flex items-center gap-2"
                    @submit.prevent="saveQuota(user)"
                >
                    <label
                        :for="`quota-${user.id}`"
                        class="text-sm text-muted-foreground"
                        >Imágenes por día</label
                    >
                    <Input
                        :id="`quota-${user.id}`"
                        :model-value="quotaValue(user)"
                        type="number"
                        min="0"
                        class="h-8 w-24"
                        :placeholder="String(defaultDailyQuota)"
                        :title="`Vacío = cuota global (${defaultDailyQuota})`"
                        @update:model-value="
                            (v) => (quotas[user.id] = String(v))
                        "
                    />
                    <Button size="sm" variant="outline" type="submit"
                        >Guardar</Button
                    >
                </form>
            </li>
        </ul>

        <nav
            v-if="users.last_page > 1"
            class="flex items-center justify-between text-sm"
            aria-label="Paginación"
        >
            <Button
                variant="outline"
                size="sm"
                :disabled="!users.prev_page_url"
                as-child
            >
                <Link :href="users.prev_page_url ?? ''" preserve-scroll
                    >Anterior</Link
                >
            </Button>
            <span class="text-muted-foreground"
                >Página {{ users.current_page }} de {{ users.last_page }}</span
            >
            <Button
                variant="outline"
                size="sm"
                :disabled="!users.next_page_url"
                as-child
            >
                <Link :href="users.next_page_url ?? ''" preserve-scroll
                    >Siguiente</Link
                >
            </Button>
        </nav>
    </div>
</template>

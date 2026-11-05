<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Clock, XCircle } from '@lucide/vue';
import { onBeforeUnmount, onMounted } from 'vue';
import { Button } from '@/components/ui/button';
import { logout } from '@/routes';

const props = defineProps<{
    status: 'pending' | 'rejected';
}>();

defineOptions({
    layout: {
        title: 'Tu cuenta está en revisión',
        description: 'Te avisamos por email cuando esté lista.',
    },
});

// Mientras espera, revisa cada 30 s: si el admin la aprueba, el servidor
// redirige solo al panel.
let timer: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    if (props.status === 'pending') {
        timer = setInterval(() => router.reload(), 30_000);
    }
});

onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <Head title="Cuenta pendiente" />

    <div class="flex flex-col items-center gap-6 text-center">
        <template v-if="status === 'rejected'">
            <XCircle class="size-12 text-destructive" />
            <p class="text-sm text-muted-foreground">
                El administrador no aprobó esta cuenta. Si creés que es un
                error, escribile a
                <a
                    href="mailto:pablo.mandile@gmail.com"
                    class="underline underline-offset-4"
                    >pablo.mandile@gmail.com</a
                >.
            </p>
        </template>

        <template v-else>
            <Clock class="size-12 animate-pulse text-primary" />
            <p class="text-sm text-muted-foreground">
                Gracias por registrarte en Lumora. Un administrador tiene que
                aprobar tu cuenta antes de que puedas crear imágenes. Esta
                pantalla se actualiza sola.
            </p>
        </template>

        <Button as-child variant="secondary">
            <Link :href="logout()" as="button">Cerrar sesión</Link>
        </Button>
    </div>
</template>

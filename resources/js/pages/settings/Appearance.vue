<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import SelectorTamanioTexto from '@/components/SelectorTamanioTexto.vue';
import { edit } from '@/routes/appearance';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Configuración de apariencia',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Configuración de apariencia" />

    <h1 class="sr-only">Configuración de apariencia</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Tamaño de la letra"
            description="Se guarda en tu cuenta y te sigue a otros dispositivos"
        />
        <SelectorTamanioTexto />
    </div>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Tema"
            description="Claro, oscuro o según el sistema"
        />
        <AppearanceTabs />
    </div>
</template>

<script setup lang="ts">
import { Download } from '@lucide/vue';
import { ref } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { usePwaInstall } from '@/composables/usePwaInstall';

const { canInstall, isIos, install } = usePwaInstall();
const showHelp = ref(false);
const busy = ref(false);

async function onClick(): Promise<void> {
    if (busy.value) {
        return;
    }

    busy.value = true;

    try {
        // 'manual' = iOS, o el aviso del navegador ya se usó.
        if ((await install()) === 'manual') {
            showHelp.value = true;
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <SidebarMenu v-if="canInstall">
        <SidebarMenuItem>
            <SidebarMenuButton
                :disabled="busy"
                tooltip="Instalar app"
                data-test="install-app"
                @click="onClick"
            >
                <Download />
                <span>Instalar app</span>
            </SidebarMenuButton>
        </SidebarMenuItem>
    </SidebarMenu>

    <Dialog v-model:open="showHelp">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Instalar Lumora</DialogTitle>
                <DialogDescription>
                    Queda como una app más, con su ícono, y se abre sin la barra
                    del navegador.
                </DialogDescription>
            </DialogHeader>
            <ol v-if="isIos" class="list-decimal space-y-1.5 pl-5 text-sm">
                <li>Tocá <strong>Compartir</strong> en la barra de Safari.</li>
                <li>Elegí <strong>Agregar a inicio</strong>.</li>
                <li>Confirmá con <strong>Agregar</strong>.</li>
            </ol>
            <ol v-else class="list-decimal space-y-1.5 pl-5 text-sm">
                <li>Abrí el menú del navegador (los tres puntos).</li>
                <li>
                    Elegí <strong>Instalar Lumora</strong> o
                    <strong>Agregar a la pantalla de inicio</strong>.
                </li>
            </ol>
        </DialogContent>
    </Dialog>
</template>

<script setup lang="ts">
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { MoreHorizontal, Pencil, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuAction,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { destroy, show, update } from '@/routes/conversations';
import type { ConversationItem } from '@/types';

const TITLE_MAX = 60;

const page = usePage();
const { isCurrentUrl } = useCurrentUrl();

const conversations = computed(() => page.props.conversations ?? []);

/** La conversación abierta, para volver a una nueva si se la borra. */
const currentId = computed(
    () => conversations.value.find((c) => isCurrentUrl(show(c.id)))?.id ?? null,
);

const renaming = ref<ConversationItem | null>(null);
const deleting = ref<ConversationItem | null>(null);
const removing = ref(false);
const form = useForm({ title: '' });

// El diálogo se abre después de que el menú desplegable terminó de cerrarse,
// para que no se pisen los dos manejando el foco.
function startRename(conversation: ConversationItem): void {
    setTimeout(() => {
        form.clearErrors();
        form.title = conversation.title;
        renaming.value = conversation;
    });
}

/** Al abrir, el nombre queda seleccionado: escribir lo reemplaza entero. */
function selectTitle(event: Event): void {
    event.preventDefault();
    const input = document.getElementById(
        'conversation-title',
    ) as HTMLInputElement | null;
    input?.focus();
    input?.select();
}

function startDelete(conversation: ConversationItem): void {
    setTimeout(() => (deleting.value = conversation));
}

function saveTitle(): void {
    if (!renaming.value) {
        return;
    }

    form.patch(update(renaming.value.id).url, {
        preserveScroll: true,
        preserveState: true,
        only: ['conversations', 'conversation'],
        onSuccess: () => (renaming.value = null),
    });
}

function confirmDelete(): void {
    if (!deleting.value) {
        return;
    }

    removing.value = true;
    router.delete(destroy(deleting.value.id).url, {
        data: { current: currentId.value },
        preserveScroll: true,
        onFinish: () => {
            removing.value = false;
            deleting.value = null;
        },
    });
}
</script>

<template>
    <SidebarGroup
        v-if="conversations.length"
        class="min-h-0 px-2 py-0 group-data-[collapsible=icon]:hidden"
        data-test="conversations"
    >
        <SidebarGroupLabel>Conversaciones</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem
                v-for="conversation in conversations"
                :key="conversation.id"
            >
                <SidebarMenuButton
                    as-child
                    :is-active="isCurrentUrl(show(conversation.id))"
                    :tooltip="conversation.title"
                    class="pr-8"
                >
                    <Link :href="show(conversation.id)">
                        <span class="truncate">{{ conversation.title }}</span>
                    </Link>
                </SidebarMenuButton>

                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <SidebarMenuAction show-on-hover>
                            <MoreHorizontal />
                            <span class="sr-only"
                                >Opciones de «{{ conversation.title }}»</span
                            >
                        </SidebarMenuAction>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent side="right" align="start">
                        <DropdownMenuItem @select="startRename(conversation)">
                            <Pencil />
                            Renombrar
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            variant="destructive"
                            @select="startDelete(conversation)"
                        >
                            <Trash2 />
                            Eliminar
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>

    <Dialog
        :open="renaming !== null"
        @update:open="(open) => !open && (renaming = null)"
    >
        <DialogContent class="sm:max-w-md" @open-auto-focus="selectTitle">
            <form class="grid gap-4" @submit.prevent="saveTitle">
                <DialogHeader>
                    <DialogTitle>Renombrar conversación</DialogTitle>
                    <DialogDescription class="sr-only">
                        Escribí el nombre nuevo.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-1.5">
                    <Input
                        id="conversation-title"
                        v-model="form.title"
                        :maxlength="TITLE_MAX"
                        aria-label="Nombre"
                        required
                    />
                    <p
                        v-if="form.errors.title"
                        class="text-xs text-destructive"
                    >
                        {{ form.errors.title }}
                    </p>
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="renaming = null"
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="submit"
                        :disabled="form.processing || !form.title.trim()"
                    >
                        Guardar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="deleting !== null"
        @update:open="(open) => !open && (deleting = null)"
    >
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>¿Eliminar la conversación?</DialogTitle>
                <DialogDescription>
                    Se va del menú «{{ deleting?.title }}». Las imágenes no se
                    borran: siguen en la galería.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="outline" @click="deleting = null">
                    Cancelar
                </Button>
                <Button
                    variant="destructive"
                    :disabled="removing"
                    @click="confirmDelete"
                >
                    Eliminar
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

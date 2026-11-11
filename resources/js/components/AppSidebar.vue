<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    ChartColumn,
    Images,
    SlidersHorizontal,
    SquarePen,
    Users,
} from '@lucide/vue';
import { computed, onUnmounted } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import InstallAppButton from '@/components/InstallAppButton.vue';
import NavConversations from '@/components/NavConversations.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { dashboard, gallery } from '@/routes';
import { stats as adminStats } from '@/routes/admin';
import { index as adminSettings } from '@/routes/admin/settings';
import { index as adminUsers } from '@/routes/admin/users';
import type { NavItem } from '@/types';

const page = usePage();
const { isMobile, setOpenMobile } = useSidebar();

// En el celular el menú es un sheet que la navegación de Inertia no desmonta:
// se cierra al cambiar de pantalla. Solo si cambió la URL, porque el estudio
// recarga sus datos cada 3 s mientras genera y eso también es un "navigate".
let lastPath = window.location.pathname;
onUnmounted(
    router.on('navigate', () => {
        const path = window.location.pathname;

        if (isMobile.value && path !== lastPath) {
            setOpenMobile(false);
        }

        lastPath = path;
    }),
);

const mainNavItems = computed<NavItem[]>(() => [
    // El estudio sin conversación es una nueva: el primer pedido la crea.
    { title: 'Nueva conversación', href: dashboard(), icon: SquarePen },
    { title: 'Galería', href: gallery(), icon: Images },
    ...(page.props.auth.is_admin
        ? [
              { title: 'Usuarios', href: adminUsers(), icon: Users },
              {
                  title: 'Estadísticas',
                  href: adminStats(),
                  icon: ChartColumn,
              },
              {
                  title: 'Ajustes',
                  href: adminSettings(),
                  icon: SlidersHorizontal,
              },
          ]
        : []),
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
            <NavConversations />
        </SidebarContent>

        <SidebarFooter>
            <InstallAppButton />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>

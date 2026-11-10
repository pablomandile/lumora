<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Images, SlidersHorizontal, Sparkles, Users } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
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
} from '@/components/ui/sidebar';
import { dashboard, gallery } from '@/routes';
import { index as adminSettings } from '@/routes/admin/settings';
import { index as adminUsers } from '@/routes/admin/users';
import type { NavItem } from '@/types';

const page = usePage();

const mainNavItems = computed<NavItem[]>(() => [
    { title: 'Estudio', href: dashboard(), icon: Sparkles },
    { title: 'Galería', href: gallery(), icon: Images },
    ...(page.props.auth.is_admin
        ? [
              { title: 'Usuarios', href: adminUsers(), icon: Users },
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
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>

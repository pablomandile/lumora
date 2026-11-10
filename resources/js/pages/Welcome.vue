<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Download, Images, Sparkles, Zap } from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { dashboard, login, privacidad, register, terminos } from '@/routes';

const features = [
    {
        icon: Sparkles,
        title: 'Describí y listo',
        text: 'Escribís lo que imaginás y en unos segundos tenés la imagen.',
    },
    {
        icon: Zap,
        title: 'Rápido',
        text: 'Cada imagen sale en unos 20 segundos, en el formato que elijas.',
    },
    {
        icon: Images,
        title: 'Tu galería privada',
        text: 'Tus imágenes las ves solo vos. Marcá favoritas y buscá por prompt.',
    },
    {
        icon: Download,
        title: 'Descargá como quieras',
        text: 'PNG original, o JPG y WebP más livianos para compartir.',
    },
];
</script>

<template>
    <Head title="Imágenes con IA" />

    <div class="flex min-h-dvh flex-col bg-background text-foreground">
        <header
            class="mx-auto flex w-full max-w-5xl items-center justify-between gap-3 px-4 py-4 sm:px-6"
        >
            <div class="flex items-center gap-2 font-semibold">
                <AppLogoIcon class="size-8" />
                Lumora
            </div>
            <nav class="flex items-center gap-2">
                <Button v-if="$page.props.auth.user" as-child>
                    <Link :href="dashboard()">Ir al estudio</Link>
                </Button>
                <template v-else>
                    <Button variant="ghost" as-child>
                        <Link :href="login()">Ingresar</Link>
                    </Button>
                    <Button as-child class="max-sm:hidden">
                        <Link :href="register()">Crear cuenta</Link>
                    </Button>
                </template>
            </nav>
        </header>

        <main
            class="mx-auto flex w-full max-w-5xl flex-1 flex-col px-4 sm:px-6"
        >
            <section
                class="flex flex-col items-center gap-6 py-14 text-center sm:py-24"
            >
                <AppLogoIcon class="size-16 drop-shadow-lg sm:size-20" />
                <h1
                    class="max-w-2xl text-3xl font-semibold tracking-tight text-balance sm:text-5xl"
                >
                    Convertí tus ideas en
                    <span
                        class="bg-gradient-to-r from-violet-500 to-sky-500 bg-clip-text text-transparent"
                        >imágenes</span
                    >
                </h1>
                <p class="max-w-xl text-base text-pretty text-muted-foreground">
                    Lumora genera imágenes a partir de una descripción, con un
                    modelo de inteligencia artificial que corre en una placa
                    propia. Pedí acceso y empezá a crear.
                </p>
                <div class="flex flex-wrap justify-center gap-3">
                    <Button v-if="$page.props.auth.user" size="lg" as-child>
                        <Link :href="dashboard()">
                            <Sparkles />
                            Ir al estudio
                        </Link>
                    </Button>
                    <template v-else>
                        <Button size="lg" as-child>
                            <Link :href="register()">
                                <Sparkles />
                                Pedir acceso
                            </Link>
                        </Button>
                        <Button size="lg" variant="outline" as-child>
                            <Link :href="login()">Ya tengo cuenta</Link>
                        </Button>
                    </template>
                </div>
                <p
                    v-if="!$page.props.auth.user"
                    class="text-xs text-muted-foreground"
                >
                    Las cuentas nuevas quedan pendientes hasta que un
                    administrador las aprueba.
                </p>
            </section>

            <section
                class="grid gap-4 pb-16 sm:grid-cols-2 lg:grid-cols-4"
                aria-label="Qué ofrece Lumora"
            >
                <div
                    v-for="feature in features"
                    :key="feature.title"
                    class="rounded-xl border bg-card p-5"
                >
                    <component
                        :is="feature.icon"
                        class="mb-3 size-5 text-violet-500"
                    />
                    <h2 class="mb-1 text-sm font-medium">
                        {{ feature.title }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{ feature.text }}
                    </p>
                </div>
            </section>
        </main>

        <footer
            class="mx-auto flex w-full max-w-5xl flex-wrap items-center justify-between gap-3 border-t px-4 py-6 text-xs text-muted-foreground sm:px-6"
            data-test="site-footer"
        >
            <span>© {{ new Date().getFullYear() }} Lumora</span>
            <nav class="flex gap-4">
                <Link :href="terminos()" class="hover:text-foreground"
                    >Términos y condiciones</Link
                >
                <Link :href="privacidad()" class="hover:text-foreground"
                    >Privacidad</Link
                >
            </nav>
        </footer>
    </div>
</template>

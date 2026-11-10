<script setup lang="ts">
import { useId } from 'vue';

// Isotipo de Lumora: la L, la chispa y la órbita (fuente: resources/icons/lumora-mark.svg).
defineOptions({ inheritAttrs: false });

// Ids propios por instancia: con dos logos en la página, los degradados no se pisan.
const uid = `lumora-${useId()}`;
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 512 512"
        aria-hidden="true"
        v-bind="$attrs"
    >
        <defs>
            <linearGradient
                :id="`${uid}-lm-stem`"
                x1="0.2"
                y1="0"
                x2="0.5"
                y2="1"
            >
                <stop offset="0" stop-color="#ffd27a" />
                <stop offset="0.45" stop-color="#ff9a3c" />
                <stop offset="1" stop-color="#ff3d6e" />
            </linearGradient>
            <linearGradient
                :id="`${uid}-lm-shade`"
                x1="1"
                y1="0"
                x2="0.1"
                y2="1"
            >
                <stop offset="0" stop-color="#ff7a2e" />
                <stop offset="0.55" stop-color="#e0254f" />
                <stop offset="1" stop-color="#7a0a3c" />
            </linearGradient>
            <linearGradient
                :id="`${uid}-lm-wave`"
                x1="0"
                y1="0"
                x2="1"
                y2="0.35"
            >
                <stop offset="0" stop-color="#ffb347" />
                <stop offset="0.35" stop-color="#ff7a2e" />
                <stop offset="0.7" stop-color="#ff2d55" />
                <stop offset="1" stop-color="#ff4fb0" />
            </linearGradient>
            <linearGradient
                :id="`${uid}-lm-wave-light`"
                x1="0"
                y1="0"
                x2="0"
                y2="1"
            >
                <stop offset="0" stop-color="#fff3c4" stop-opacity="0.75" />
                <stop offset="0.45" stop-color="#ffd27a" stop-opacity="0" />
            </linearGradient>
            <linearGradient :id="`${uid}-lm-orbit`" x1="0" y1="0" x2="1" y2="0">
                <stop offset="0" stop-color="#ffb347" />
                <stop offset="0.6" stop-color="#ff5a3c" />
                <stop offset="1" stop-color="#ff3d8b" />
            </linearGradient>
            <linearGradient :id="`${uid}-lm-spark`" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" stop-color="#fff0b0" />
                <stop offset="0.5" stop-color="#ffc24d" />
                <stop offset="1" stop-color="#ff8a2e" />
            </linearGradient>
        </defs>
        <g transform="translate(-9 -6)">
            <path
                :fill="`url(#${uid}-lm-orbit)`"
                d="M392 132C446 124 498 136 494 172C490 208 446 240 396 266C434 238 474 206 476 176C478 150 440 138 392 132Z"
            />
            <path
                :fill="`url(#${uid}-lm-stem)`"
                d="M128 340C150 250 196 126 238 92C268 66 320 78 314 118C306 180 298 240 294 304Z"
            />
            <path
                :fill="`url(#${uid}-lm-shade)`"
                d="M314 118C306 180 298 240 294 304L148 336C196 262 258 186 314 118Z"
            />
            <path
                :fill="`url(#${uid}-lm-wave)`"
                d="M172 190C104 220 34 258 40 304C46 352 112 386 196 382C262 378 302 358 352 356C402 352 442 372 464 414C458 330 424 266 372 260C330 256 290 276 230 300C150 332 76 330 62 300C56 270 110 226 172 190Z"
            />
            <path
                :fill="`url(#${uid}-lm-wave-light)`"
                d="M172 190C104 220 34 258 40 304C46 352 112 386 196 382C262 378 302 358 352 356C402 352 442 372 464 414C458 330 424 266 372 260C330 256 290 276 230 300C150 332 76 330 62 300C56 270 110 226 172 190Z"
            />
            <path
                :fill="`url(#${uid}-lm-spark)`"
                d="M368 110C372 150 380 160 412 168C380 176 372 186 368 228C364 186 356 176 324 168C356 160 364 150 368 110Z"
            />
        </g>
    </svg>
</template>

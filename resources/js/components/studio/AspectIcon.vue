<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ width: number; height: number }>();

// Rectángulo con la proporción, inscripto en un cuadrado de 14 px.
const style = computed(() => {
    const ratio = props.width / props.height;

    return ratio >= 1
        ? { width: '14px', height: `${Math.max(5, 14 / ratio)}px` }
        : { width: `${Math.max(5, 14 * ratio)}px`, height: '14px' };
});
</script>

<template>
    <span class="inline-flex size-4 shrink-0 items-center justify-center">
        <span
            class="rounded-[2px] border-[1.5px] border-current"
            :style="style"
        />
    </span>
</template>

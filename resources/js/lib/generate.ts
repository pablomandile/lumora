import { router } from '@inertiajs/vue3';
import { store } from '@/routes/generations';

export type GenerationRequest = {
    prompt: string;
    aspect?: string | null;
    seed?: number | null;
    width?: number;
    height?: number;
    /** Sin conversación, el servidor abre una nueva. */
    conversation_id?: string | null;
};

/**
 * Pide una imagen sin pasar por la barra (otra variante, reintentar).
 * El servidor redirige a la conversación, así que desde la galería se navega allá.
 */
export function requestGeneration(
    data: GenerationRequest,
    only?: string[],
): void {
    const custom = !data.aspect;

    router.post(
        store().url,
        {
            prompt: data.prompt,
            aspect: custom ? 'custom' : data.aspect,
            seed: data.seed ?? null,
            width: custom ? (data.width ?? null) : null,
            height: custom ? (data.height ?? null) : null,
            conversation_id: data.conversation_id ?? null,
        },
        { preserveScroll: true, ...(only ? { only } : {}) },
    );
}

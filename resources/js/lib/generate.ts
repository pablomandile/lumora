import { router } from '@inertiajs/vue3';
import { store } from '@/routes/generations';

export type GenerationRequest = {
    prompt: string;
    aspect?: string | null;
    seed?: number | null;
    width?: number;
    height?: number;
    /** El modelo del pedido original (sin él, el servidor usa el de siempre). */
    model?: string | null;
    /** Imágenes de entrada ya guardadas: otra variante de una edición. */
    attachment_ids?: string[];
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
    const editing = (data.attachment_ids?.length ?? 0) > 0;
    // Una edición toma el tamaño de su imagen: no se manda aspecto.
    const custom = !editing && !data.aspect;

    router.post(
        store().url,
        {
            prompt: data.prompt,
            model: data.model ?? null,
            aspect: editing ? null : custom ? 'custom' : data.aspect,
            seed: data.seed ?? null,
            width: custom ? (data.width ?? null) : null,
            height: custom ? (data.height ?? null) : null,
            attachment_ids: editing ? data.attachment_ids : [],
            conversation_id: data.conversation_id ?? null,
        },
        { preserveScroll: true, ...(only ? { only } : {}) },
    );
}

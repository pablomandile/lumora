export type ImageFormat = 'png' | 'jpg' | 'webp';

export type ImageItem = {
    id: string;
    generation_id: string;
    conversation_id: string | null;
    url: string;
    thumb_url: string;
    downloads: Record<ImageFormat, string>;
    width: number;
    height: number;
    bytes: number;
    favorite: boolean;
    created_at: string | null;
    prompt: string;
    model: string;
    aspect: string | null;
    seed: number | null;
    steps: number | null;
    run_seconds: number | null;
};

/** Una conversación del estudio, como aparece en el menú. */
export type ConversationItem = {
    id: string;
    title: string;
};

export type GenerationStatus =
    | 'queued'
    | 'waiting_gpu'
    | 'running'
    | 'done'
    | 'failed';

export type GenerationItem = {
    id: string;
    prompt: string;
    model: string;
    width: number;
    height: number;
    aspect: string | null;
    steps: number | null;
    seed: number | null;
    status: GenerationStatus;
    status_label: string;
    progress: number | null;
    queue_position: number | null;
    error: string | null;
    created_at: string | null;
    image: ImageItem | null;
};

export type AspectOption = { key: string; width: number; height: number };

export type ModelOption = {
    key: string;
    label: string;
    default_steps: number;
    max_steps: number;
};

export type Quota = {
    limit: number | null;
    used: number;
    remaining: number | null;
};

/** Lo que se le pasa a la barra para volver a pedir una imagen. */
export type PromptPreset = {
    prompt: string;
    aspect?: string | null;
    seed?: number | null;
};

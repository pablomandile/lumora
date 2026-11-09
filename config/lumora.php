<?php

return [

    /*
    | Email del administrador. Quien se registre con este email queda como
    | administrador y aprobado automáticamente.
    */
    'admin_email' => env('LUMORA_ADMIN_EMAIL', 'pablo.mandile@gmail.com'),

    /*
    | Cantidad de imágenes por día que puede generar un usuario aprobado
    | cuando no tiene una cuota propia. El admin la puede cambiar en Ajustes.
    */
    'default_daily_quota' => (int) env('LUMORA_DEFAULT_DAILY_QUOTA', 20),

    /*
    | Zona horaria con la que se cuenta "el día" de la cuota.
    */
    'quota_timezone' => env('LUMORA_QUOTA_TIMEZONE', 'America/Argentina/Buenos_Aires'),

    /*
    | Días que se guardan las imágenes (las favoritas no se borran nunca).
    | 0 = no borrar. El admin lo puede cambiar en Ajustes.
    */
    'retention_days' => (int) env('LUMORA_RETENTION_DAYS', 0),

    /*
    | Tamaños por relación de aspecto (Z-Image Turbo rinde mejor cerca de 1 MP).
    */
    'aspects' => [
        '1:1' => [1024, 1024],
        '4:3' => [1152, 864],
        '3:4' => [864, 1152],
        '3:2' => [1216, 832],
        '2:3' => [832, 1216],
        '16:9' => [1344, 768],
        '9:16' => [768, 1344],
        '21:9' => [1536, 640],
    ],

    // Lado máximo que puede pedir el admin con tamaño libre.
    'admin_max_side' => 2048,

    'gpu' => [
        // Cada cuánto se consulta el estado de la placa para el indicador.
        'status_ttl_seconds' => 30,
        // Cuánto espera un Job a que el gateway termine antes de soltarse y
        // volver a mirar más tarde (no tiene que superar el timeout del Job).
        'poll_budget_seconds' => (int) env('LUMORA_GPU_POLL_BUDGET', 240),
        'poll_interval_seconds' => 2,
        // Si la placa está apagada, se reintenta cada tanto...
        'retry_delay_seconds' => 60,
        // ...hasta este límite: después la generación se da por fallida.
        'max_wait_hours' => (int) env('LUMORA_GPU_MAX_WAIT_HOURS', 12),
    ],

    'thumb_width' => 384,

];

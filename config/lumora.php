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

];

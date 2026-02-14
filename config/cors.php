<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    // Métodos HTTP permitidos
    'allowed_methods' => ['*'],

    // Orígenes permitidos
    'allowed_origins' => [
        'http://localhost:3000',
        'http://localhost:4000',
        'http://192.168.18.198:4000',
        'https://vv1g8thv-3000.brs.devtunnels.ms',
        'https://playful-genie-d92cb7.netlify.app', // Tu dominio de frontend
        'http://192.168.18.102:3000',
    ],

    // Patrones de orígenes permitidos (opcional)
    'allowed_origins_patterns' => [],

    // Encabezados permitidos
    'allowed_headers' => ['Content-Type', 'X-Requested-With', 'Authorization', 'X-CSRF-Token'],

    // Encabezados expuestos (opcional)
    'exposed_headers' => [],

    // Tiempo máximo que se puede cachear la respuesta
    'max_age' => 0,

    // Soporte para credenciales (como cookies o tokens)
    'supports_credentials' => true,
];

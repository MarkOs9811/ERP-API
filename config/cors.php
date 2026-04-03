<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    // Métodos HTTP permitidos
    'allowed_methods' => ['*'],

    // Orígenes permitidos
    'allowed_origins' => [
        'http://localhost:3000',
        'http://localhost:4000',
        'https://lustrous-cupcake-b9cf4a.netlify.app',

    ],

    // Patrones de orígenes permitidos (opcional)
    'allowed_origins_patterns' => [],

    // Encabezados permitidos
    'allowed_headers' => ['*', 'Content-Type', 'X-Requested-With', 'Authorization', 'X-CSRF-Token'],

    // Encabezados expuestos (opcional)
    'exposed_headers' => [],

    // Tiempo máximo que se puede cachear la respuesta
    'max_age' => 0,

    // Soporte para credenciales (como cookies o tokens)
    'supports_credentials' => true,
];

<?php

use App\Helpers\ConfiguracionHelper;

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // los datos vacios se sobrescribiran de la configuraciond e la db
    'openai' => [
        'api_key' => '',
    ],

    'google' => [
        'client_id' => '',
        'client_secret' => '',
        'redirect' => '',
        'scopes' => [
            'openid',
            'profile',
            'email',
            'https://www.googleapis.com/auth/calendar.events',
            'https://www.googleapis.com/auth/spreadsheets',
            'https://www.googleapis.com/auth/drive', // ¡ESTE ES EL QUE FALTA!
        ],
        'with' => [
            'access_type' => 'offline',
        ],
    ],



];

<?php

/*
 * Lectura del buzón donde los bancos reportan las fallas. Mientras no haya
 * usuario y contraseña, la lectura queda apagada.
 */
return [
    'host' => env('INBOUND_MAIL_HOST'),
    'port' => (int) env('INBOUND_MAIL_PORT', 993),
    'encryption' => env('INBOUND_MAIL_ENCRYPTION', 'ssl'),
    'username' => env('INBOUND_MAIL_USERNAME'),
    'password' => env('INBOUND_MAIL_PASSWORD'),
    'folder' => env('INBOUND_MAIL_FOLDER', 'INBOX'),

    // Nada anterior a esta fecha se procesa: evita abrir tickets con el correo viejo del buzón.
    'since' => env('INBOUND_MAIL_SINCE'),

    // Dominios de los bancos. Solo sus correos abren tickets sin intervención.
    'domains' => array_values(array_filter(array_map('trim', explode(',', strtolower((string) env('INBOUND_MAIL_DOMAINS', 'bncenlinea.net')))))),

    // Servidor cuya verificación de autenticidad (SPF/DKIM) se da por buena: el antispam del hosting.
    'trusted_authserv' => strtolower((string) env('INBOUND_MAIL_AUTHSERV', 'mailspamprotection.com')),
];

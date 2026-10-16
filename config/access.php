<?php
declare(strict_types=1);

/**
 * La aplicación solo atiende al propio equipo (127.0.0.1 / ::1). En Docker las
 * peticiones llegan desde la puerta de enlace de la red del contenedor; esa IP
 * se añade con LOCAL_ACCESS_EXTRA_IPS (lista separada por comas), mientras el
 * puerto se publica únicamente en 127.0.0.1 del equipo.
 */
function isLocalRequest(): bool
{
    $allowed = ['127.0.0.1', '::1'];
    foreach (explode(',', (string) getenv('LOCAL_ACCESS_EXTRA_IPS')) as $ip) {
        $ip = trim($ip);
        if ($ip !== '') {
            $allowed[] = $ip;
        }
    }

    return in_array($_SERVER['REMOTE_ADDR'] ?? '', $allowed, true);
}

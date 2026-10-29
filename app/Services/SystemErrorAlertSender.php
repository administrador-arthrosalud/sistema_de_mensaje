<?php
declare(strict_types=1);

/** Entrega alertas técnicas agregadas; si Gestionalo falla, quedan pendientes para reintento. */
final class SystemErrorAlertSender
{
    private const COMPONENT_LABELS = [
        'chatbot_api' => 'API de WhatsApp',
        'auto_reminders' => 'Worker de recordatorios',
        'host_watchdog' => 'Vigilancia de Windows',
    ];

    private const EVENT_LABELS = [
        'api_client_init_error' => 'No se pudo iniciar la conexión con Gestionalo',
        'api_transport_error' => 'Gestionalo no respondió a la conexión',
        'api_http_error' => 'Gestionalo devolvió un error HTTP',
        'api_no_whatsapp' => 'Gestionalo no encontró WhatsApp para un destino',
        'worker_error' => 'El worker de recordatorios registró un error',
        'docker_daemon_unavailable' => 'Docker no está respondiendo',
        'database_container_started' => 'Se inició el contenedor de la base local',
        'database_container_start_failed' => 'No se pudo iniciar el contenedor de la base local',
        'database_health_failed' => 'La base local no responde',
        'database_container_stopped' => 'El contenedor de la base local está detenido',
        'web_container_started' => 'Se inició el contenedor web',
        'web_container_start_failed' => 'No se pudo iniciar el contenedor web',
        'web_container_stopped' => 'El contenedor web está detenido',
        'web_http_health_failed' => 'La página web local no responde',
        'sync_container_started' => 'Se inició el contenedor de sincronización',
        'sync_container_start_failed' => 'No se pudo iniciar el contenedor de sincronización',
        'sync_container_stopped' => 'El contenedor de sincronización está detenido',
        'sync_worker_heartbeat_stale' => 'El sincronizador SIGES dejó de actualizar su latido',
        'sync_worker_restarted' => 'Se reinició el sincronizador SIGES por falta de latido',
        'sync_worker_restart_failed' => 'No se pudo reiniciar el sincronizador SIGES',
        'auto_reminders_container_started' => 'Se inició el contenedor de recordatorios automáticos',
        'auto_reminders_container_start_failed' => 'No se pudo iniciar el contenedor de recordatorios automáticos',
        'auto_reminders_container_stopped' => 'El contenedor de recordatorios automáticos está detenido',
        'auto_reminders_worker_heartbeat_stale' => 'El worker de recordatorios no actualiza su latido',
        'auto_reminders_worker_restarted' => 'Se reinició el worker de recordatorios mientras estaba pausado',
        'auto_reminders_worker_restart_failed' => 'No se pudo reiniciar el worker de recordatorios',
        'container_not_found' => 'Falta un contenedor esperado',
    ];

    public function __construct(private PDO $database)
    {
    }

    public function flush(): int
    {
        require_once __DIR__ . '/../Repositories/SettingsRepository.php';
        require_once __DIR__ . '/../Repositories/SystemErrorAlertRepository.php';
        require_once __DIR__ . '/ChatbotApiClient.php';

        $destination = trim((new SettingsRepository($this->database))->get(SettingsRepository::ERROR_ALERT_DESTINATION));
        if ($destination === '') {
            return 0;
        }

        $repository = new SystemErrorAlertRepository($this->database);
        $rows = $repository->pending();
        if ($rows === []) {
            return 0;
        }

        $groups = [];
        foreach ($rows as $row) {
            $key = implode('|', [(string) $row['component'], (string) $row['error_code'], (string) $row['http_status']]);
            $groups[$key]['component'] = (string) $row['component'];
            $groups[$key]['error_code'] = (string) $row['error_code'];
            $groups[$key]['http_status'] = (int) $row['http_status'];
            $groups[$key]['occurrences'] = ($groups[$key]['occurrences'] ?? 0) + (int) $row['occurrences'];
            $groups[$key]['first_seen_at'][] = (string) $row['first_seen_at'];
            $groups[$key]['last_seen_at'][] = (string) $row['last_seen_at'];
            $groups[$key]['ids'][] = (int) $row['id'];
        }

        try {
            $client = ChatbotApiClient::fromEnvironment();
        } catch (Throwable) {
            foreach ($groups as $group) {
                $repository->deferRetry($group['ids']);
            }
            error_log('No se pudo preparar el envío de alertas técnicas por WhatsApp.');
            return 0;
        }

        $sent = 0;
        foreach ($groups as $group) {
            sort($group['first_seen_at']);
            sort($group['last_seen_at']);
            $http = $group['http_status'] === 0 ? 'sin respuesta HTTP' : (string) $group['http_status'];
            $message = implode("\n", [
                'Alerta técnica de Chatbot',
                'Componente: ' . (self::COMPONENT_LABELS[$group['component']] ?? $group['component']),
                'Evento: ' . (self::EVENT_LABELS[$group['error_code']] ?? $group['error_code']),
                'HTTP: ' . $http,
                'Ocurrencias: ' . $group['occurrences'],
                'Periodo: ' . $group['first_seen_at'][0] . ' a ' . $group['last_seen_at'][count($group['last_seen_at']) - 1],
            ]);

            try {
                $client->sendOperationalAlert($destination, $message);
                $repository->markSent($group['ids']);
                $sent++;
            } catch (Throwable) {
                $repository->deferRetry($group['ids']);
                error_log('No se pudo entregar una alerta técnica por WhatsApp; se reintentará.');
            }
        }

        return $sent;
    }
}

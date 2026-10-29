<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$events = [
    'docker_daemon_unavailable', 'database_container_started', 'database_container_start_failed', 'database_health_failed', 'database_container_stopped',
    'web_container_started', 'web_container_start_failed', 'web_container_stopped', 'web_http_health_failed',
    'sync_container_started', 'sync_container_start_failed', 'sync_container_stopped',
    'sync_worker_heartbeat_stale', 'sync_worker_restarted', 'sync_worker_restart_failed',
    'auto_reminders_container_started', 'auto_reminders_container_start_failed', 'auto_reminders_container_stopped',
    'auto_reminders_worker_heartbeat_stale', 'auto_reminders_worker_restarted', 'auto_reminders_worker_restart_failed',
    'container_not_found',
];
$event = (string) ($argv[1] ?? '');
if (!in_array($event, $events, true)) {
    fwrite(STDERR, "Evento no permitido.\n");
    exit(2);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Repositories/SystemErrorAlertRepository.php';

try {
    (new SystemErrorAlertRepository(databaseConnection()))->record('host_watchdog', $event);
    fwrite(STDOUT, "recorded\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'queue_unavailable (' . get_class($error) . ")\n");
    exit(1);
}

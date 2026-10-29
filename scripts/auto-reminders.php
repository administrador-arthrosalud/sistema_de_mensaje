<?php
declare(strict_types=1);

/**
 * Worker del envío masivo automático de la víspera (ver AutoReminderScheduler para el calendario).
 * Un administrador lo enciende y lo pausa en "Estado del sistema" (app_settings.auto_reminders_enabled,
 * apagado por defecto). Usa las mismas reglas y el mismo BulkReminderSender que el envío masivo manual.
 *
 *   php scripts/auto-reminders.php            # worker (contenedor auto-reminders)
 *   php scripts/auto-reminders.php --once     # una sola pasada
 *   php scripts/auto-reminders.php --dry-run  # solo cuenta las citas que enviaría; NO envía ni escribe
 *
 * Los registros solo llevan conteos: nunca nombres, celulares ni textos de pacientes.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Repositories/AppointmentRepository.php';
require_once __DIR__ . '/../app/Repositories/AppointmentReminderRepository.php';
require_once __DIR__ . '/../app/Repositories/SettingsRepository.php';
require_once __DIR__ . '/../app/Services/ChatbotApiClient.php';
require_once __DIR__ . '/../app/Services/ReminderMessageBuilder.php';
require_once __DIR__ . '/../app/Services/BulkReminderSender.php';
require_once __DIR__ . '/../app/Services/AutoReminderScheduler.php';
require_once __DIR__ . '/../app/Services/SystemErrorAlertQueue.php';
require_once __DIR__ . '/../app/Services/SystemErrorAlertSender.php';

const AUTO_LOOP_SECONDS = 60;   // latido, lectura del interruptor y revisión de citas que aparecen tarde

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Lima');

$dryRun = in_array('--dry-run', $argv, true);
$once = $dryRun || in_array('--once', $argv, true);

$lock = fopen(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siges-auto-reminders.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Ya hay un worker de envío automático en ejecución.\n");
    exit(1);
}

/** Una pasada: devuelve los conteos por fecha objetivo, o null si no tocaba enviar. */
$pass = static function (PDO $database, bool $dryRun): ?array {
    $settings = new SettingsRepository($database);
    $now = new DateTimeImmutable('now');
    if (!$dryRun) {
        $settings->set(SettingsRepository::AUTO_LAST_RUN, $now->format('Y-m-d H:i:s'));
        if (!$settings->autoRemindersEnabled()) {
            return null;
        }
    }

    $dates = AutoReminderScheduler::targetDates($now);
    if ($dates === []) {
        return null;
    }
    if (!$dryRun && !ChatbotApiClient::isConfigured()) {
        fwrite(STDERR, "Envío automático: falta CHATBOT_LINE_KEY; no se envía nada.\n");
        return null;
    }

    $appointments = new AppointmentRepository($database);
    $reminders = new AppointmentReminderRepository($database);
    $sender = $dryRun ? null : new BulkReminderSender($appointments, $reminders, ChatbotApiClient::fromEnvironment(), true);
    $summary = [];
    foreach ($dates as $date) {
        $ready = $appointments->findReadyForBulkReminder($date, true);
        if ($dryRun) {
            $summary[$date] = ['would_send' => count($ready)];
            continue;
        }
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $ready);
        $result = $ids === [] ? ['sent' => 0, 'failed' => 0, 'skipped' => 0] : $sender->send($ids);
        $summary[$date] = ['sent' => $result['sent'], 'failed' => $result['failed'], 'skipped' => $result['skipped']];
    }

    return $summary;
};

$database = null;
$lastErrorAt = 0;
do {
    try {
        $database ??= databaseConnection();
        if (!$dryRun) {
            (new SystemErrorAlertSender($database))->flush();
        }
        $summary = $pass($database, $dryRun);
        if ($summary !== null) {
            $total = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'would_send' => 0];
            foreach ($summary as $counts) {
                foreach ($counts as $name => $value) {
                    $total[$name] += $value;
                }
            }
            if ($dryRun) {
                printf("Simulación: se enviarían %d recordatorios (%s).\n", $total['would_send'], json_encode($summary));
            } elseif ($total['sent'] + $total['failed'] > 0) {
                (new SettingsRepository($database))->set(SettingsRepository::AUTO_LAST_SUMMARY, json_encode([
                    'at' => date('Y-m-d H:i:s'),
                    'dates' => $summary,
                ]));
                printf("Envío automático: %d enviados, %d fallidos, %d omitidos.\n", $total['sent'], $total['failed'], $total['skipped']);
            }
        }
        $lastErrorAt = 0;
    } catch (Throwable $error) {
        $database = null;
        if ($lastErrorAt === 0 || time() - $lastErrorAt >= 60) {
            fwrite(STDERR, 'Error del envío automático (' . get_class($error) . ')' . PHP_EOL);
            if (!$dryRun) {
                SystemErrorAlertQueue::record('auto_reminders', 'worker_error');
            }
            $lastErrorAt = time();
        }
    }

    if (!$once) {
        sleep(AUTO_LOOP_SECONDS);
    }
} while (!$once);

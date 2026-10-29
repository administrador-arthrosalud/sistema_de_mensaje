<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Lima');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Repositories/SettingsRepository.php';

try {
    $database = databaseConnection();
    $syncAge = $database->query(
        "SELECT EXTRACT(EPOCH FROM (LOCALTIMESTAMP(0) - last_run_at))::int
         FROM siges_sync_state WHERE sync_key = 'appointments'"
    )->fetchColumn();

    $settings = new SettingsRepository($database);
    $autoLastRun = $settings->get(SettingsRepository::AUTO_LAST_RUN);
    $autoAge = null;
    if ($autoLastRun !== '') {
        $autoAge = max(0, (new DateTimeImmutable('now'))->getTimestamp() - (new DateTimeImmutable($autoLastRun))->getTimestamp());
    }

    echo json_encode([
        'ok' => true,
        'sync_age_seconds' => $syncAge === false || $syncAge === null ? null : (int) $syncAge,
        'auto_worker_age_seconds' => $autoAge,
        'auto_enabled' => $settings->autoRemindersEnabled(),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => get_class($error)], JSON_THROW_ON_ERROR));
    exit(1);
}

<?php
declare(strict_types=1);

/** Ajustes de la aplicación en app_settings (migraciones 015 y 016). */
final class SettingsRepository
{
    public const AUTO_ENABLED = 'auto_reminders_enabled';
    public const AUTO_LAST_RUN = 'auto_reminders_last_run_at';
    public const AUTO_LAST_SUMMARY = 'auto_reminders_last_summary';
    public const ERROR_ALERT_DESTINATION = 'system_error_alert_destination';

    public function __construct(private PDO $database)
    {
    }

    public function get(string $key, string $default = ''): string
    {
        $statement = $this->database->prepare('SELECT value FROM app_settings WHERE setting_key = :key');
        $statement->execute([':key' => $key]);
        $value = $statement->fetchColumn();

        return $value === false ? $default : (string) $value;
    }

    /** Fila completa (valor y cuándo/quién la cambió), o null si no existe. */
    public function getRow(string $key): ?array
    {
        $statement = $this->database->prepare(
            'SELECT value, updated_at, updated_by_user_id FROM app_settings WHERE setting_key = :key'
        );
        $statement->execute([':key' => $key]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function set(string $key, string $value, ?int $userId = null): void
    {
        $statement = $this->database->prepare(
            'INSERT INTO app_settings (setting_key, value, updated_by_user_id) VALUES (:key, :value, :user)
             ON CONFLICT (setting_key) DO UPDATE
                SET value = EXCLUDED.value, updated_at = LOCALTIMESTAMP(0), updated_by_user_id = EXCLUDED.updated_by_user_id'
        );
        $statement->execute([':key' => $key, ':value' => $value, ':user' => $userId]);
    }

    public function autoRemindersEnabled(): bool
    {
        return $this->get(self::AUTO_ENABLED, '0') === '1';
    }
}

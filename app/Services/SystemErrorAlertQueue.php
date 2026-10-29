<?php
declare(strict_types=1);

/** Encola únicamente códigos técnicos; nunca recibe datos de pacientes ni textos de mensajes. */
final class SystemErrorAlertQueue
{
    public static function record(string $component, string $errorCode, ?int $httpStatus = null): void
    {
        try {
            require_once __DIR__ . '/../../config/database.php';
            require_once __DIR__ . '/../Repositories/SystemErrorAlertRepository.php';
            (new SystemErrorAlertRepository(databaseConnection()))->record($component, $errorCode, $httpStatus);
        } catch (Throwable) {
            error_log('No se pudo guardar una alerta técnica en la cola local.');
        }
    }
}

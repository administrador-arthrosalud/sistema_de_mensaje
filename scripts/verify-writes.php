<?php
declare(strict_types=1);

/**
 * Comprueba que las escrituras que hace la aplicación al enviar (contacto temporal,
 * intentos de recordatorio y de respuesta de chat) funcionan con los permisos reales
 * del rol de la web. Todo ocurre dentro de una transacción que SIEMPRE se deshace: no
 * deja datos ni envía mensajes.
 *   docker exec siges-dashboard-web php scripts/verify-writes.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
foreach (glob(__DIR__ . '/../app/Repositories/*.php') as $file) {
    require_once $file;
}

$_SESSION['user'] = ['id' => 1];
$database = databaseConnection();
$failures = 0;
$check = static function (string $name, callable $step) use (&$failures): void {
    try {
        $step();
        echo "  [OK]    $name\n";
    } catch (Throwable $error) {
        $failures++;
        echo "  [FALLA] $name (" . get_class($error) . ")\n";
    }
};

$database->beginTransaction();
$manual = new ManualContactRepository($database);
$contactId = 0;
$check('Agregar contacto temporal', function () use ($manual, &$contactId): void {
    $contactId = $manual->create(['patient_name' => 'PRUEBA', 'destination' => '900000000', 'appointment_date' => date('Y-m-d'), 'appointment_time' => '10:00', 'doctor_name' => '', 'sede' => '']);
});
$check('Registrar intento de recordatorio temporal', function () use ($manual, &$contactId): void {
    $manual->createReminderAttempt((array) $manual->findById($contactId), '51900000000');
});
$check('Registrar intento de recordatorio de cita SIGES', function () use ($database): void {
    $row = ['id' => 999000002, 'patient_name' => 'PRUEBA', 'appointment_date' => date('Y-m-d'), 'appointment_time' => '10:00', 'doctor_name' => ''];
    (new AppointmentReminderRepository($database))->createReminderAttempt($row, '51900000000');
});
$check('Registrar respuesta de chat saliente', function () use ($database): void {
    (new ChatRepository($database))->createOutboundAttempt('51900000000', 'prueba');
});
$check('Guardar mensaje entrante de chat', function () use ($database): void {
    (new ChatRepository($database))->insertInbound('51900000000', 'prueba', null);
});
$check('Registrar intento de recordatorio automático', function () use ($database): void {
    $row = ['id' => 999000003, 'patient_name' => 'PRUEBA', 'appointment_date' => date('Y-m-d'), 'appointment_time' => '10:00', 'doctor_name' => ''];
    (new AppointmentReminderRepository($database))->createReminderAttempt($row, '51900000000', true);
});
$check('Guardar ajuste del envío automático', function () use ($database): void {
    $settings = new SettingsRepository($database);
    $settings->set(SettingsRepository::AUTO_LAST_RUN, date('Y-m-d H:i:s'));
    $settings->autoRemindersEnabled();
});
$check('Leer latido del worker', function () use ($database): void {
    $database->query('SELECT last_run_at FROM siges_sync_state')->fetchAll();
});
if ($database->inTransaction()) {
    $database->rollBack();
}

echo $failures === 0 ? "RESULTADO: escrituras correctas (todo deshecho)\n" : "RESULTADO: $failures escrituras fallaron\n";
exit($failures === 0 ? 0 : 1);

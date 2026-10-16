<?php
declare(strict_types=1);

/**
 * Copia una sola vez los datos de la base MySQL anterior (siges_dashboard) a la
 * nueva base PostgreSQL local. Conserva los id y deja las secuencias listas.
 *
 * Requisitos: pdo_mysql y pdo_pgsql activos, database/schema.sql ya aplicado
 * en PostgreSQL y las tablas de destino vacías (si no, se detiene sin copiar).
 * Configuración en config/local.php o variables de entorno:
 *   MYSQL_SOURCE_HOST, MYSQL_SOURCE_PORT, MYSQL_SOURCE_NAME,
 *   MYSQL_SOURCE_USER, MYSQL_SOURCE_PASSWORD   (origen MySQL)
 *   PG_MIGRATION_USER, PG_MIGRATION_PASSWORD    (rol dueño de las tablas en PostgreSQL)
 *   DB_HOST, DB_PORT, DB_NAME                   (destino PostgreSQL)
 *
 * Solo imprime conteos de filas; nunca imprime datos de pacientes.
 * Uso: php scripts/migrate-mysql-to-postgresql.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$localPath = __DIR__ . '/../config/local.php';
$local = is_file($localPath) ? require $localPath : [];
$read = static function (string $name, string $default = '') use ($local): string {
    $value = getenv($name);
    return (string) (($value !== false && $value !== '') ? $value : ($local[$name] ?? $default));
};

foreach (['pdo_mysql', 'pdo_pgsql'] as $extension) {
    if (!extension_loaded($extension)) {
        fwrite(STDERR, "Activa la extensión {$extension} de PHP.\n");
        exit(1);
    }
}

$targetHost = $read('DB_HOST', '127.0.0.1');
$targetPort = $read('DB_PORT', '5432');
$targetDatabase = $read('DB_NAME', 'siges_dashboard');
$targetUser = $read('PG_MIGRATION_USER');
$targetPassword = $read('PG_MIGRATION_PASSWORD');
$sourceUser = $read('MYSQL_SOURCE_USER');
$sourcePassword = $read('MYSQL_SOURCE_PASSWORD');

if ($targetUser === '' || $sourceUser === '') {
    fwrite(STDERR, "Configura MYSQL_SOURCE_USER/MYSQL_SOURCE_PASSWORD y PG_MIGRATION_USER/PG_MIGRATION_PASSWORD.\n");
    exit(1);
}
if ($targetHost === $read('SIGES_PG_HOST') && $targetPort === $read('SIGES_PG_PORT', '5432')
    && $targetDatabase === $read('SIGES_PG_DATABASE', 'isis3_arths')) {
    fwrite(STDERR, "El destino apunta a la base de SIGES. SIGES es de solo lectura; usa la base local siges_dashboard.\n");
    exit(1);
}

// Orden y columnas de cada tabla. Las de tipo identity reciben su id original.
$tables = [
    'appointments' => ['id', 'source_key', 'siges_cita_id', 'patient_name', 'appointment_date', 'appointment_time',
        'doctor_name', 'specialty', 'sede_nombre', 'consultorio', 'modulo', 'codigo_ipress', 'appointment_note',
        'visit_reason', 'status', 'created_at', 'updated_at'],
    'siges_sync_state' => ['sync_key', 'last_cita_id', 'updated_at'],
    'manual_contacts' => ['id', 'patient_name', 'destination', 'appointment_date', 'appointment_time', 'doctor_name',
        'created_at'],
    'reminder_messages' => ['id', 'appointment_id', 'origin', 'manual_contact_id', 'destination', 'patient_name',
        'appointment_date', 'appointment_time', 'doctor_name', 'status', 'http_status', 'attempted_at', 'sent_at'],
    'chat_messages' => ['id', 'destination', 'direction', 'body', 'provider_message_id', 'status', 'http_status',
        'read_at', 'created_at'],
];
$identityTables = ['appointments', 'manual_contacts', 'reminder_messages', 'chat_messages'];

try {
    $source = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $read('MYSQL_SOURCE_HOST', '127.0.0.1'), $read('MYSQL_SOURCE_PORT', '3306'), $read('MYSQL_SOURCE_NAME', 'siges_dashboard')),
        $sourceUser,
        $sourcePassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $target = postgresConnection($targetHost, $targetPort, $targetDatabase, $targetUser, $targetPassword,
        $read('DB_SSLMODE', 'prefer'), $read('APP_TIMEZONE', 'America/Lima'));

    $sourceTables = $source->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach (array_keys($tables) as $table) {
        if ((int) $target->query("SELECT COUNT(*) FROM {$table}")->fetchColumn() > 0) {
            fwrite(STDERR, "La tabla {$table} de PostgreSQL ya tiene datos; no se copió nada.\n");
            exit(1);
        }
    }

    $target->beginTransaction();
    foreach ($tables as $table => $columns) {
        if (!in_array($table, $sourceTables, true)) {
            printf("%-18s no existe en MySQL; se omite.\n", $table);
            continue;
        }
        $columnList = implode(', ', $columns);
        $placeholders = implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns));
        $overriding = in_array($table, $identityTables, true) ? 'OVERRIDING SYSTEM VALUE' : '';
        $insert = $target->prepare("INSERT INTO {$table} ({$columnList}) {$overriding} VALUES ({$placeholders})");

        $rows = $source->query("SELECT {$columnList} FROM {$table} ORDER BY 1", PDO::FETCH_ASSOC);
        $copied = 0;
        foreach ($rows as $row) {
            $parameters = [];
            foreach ($columns as $column) {
                $parameters[':' . $column] = $row[$column];
            }
            $insert->execute($parameters);
            $copied++;
        }
        printf("%-18s %d filas copiadas.\n", $table, $copied);
    }

    foreach ($identityTables as $table) {
        $target->query(
            "SELECT setval(pg_get_serial_sequence('{$table}', 'id'), COALESCE(MAX(id), 1), MAX(id) IS NOT NULL) FROM {$table}"
        );
    }
    $target->commit();
    echo "Migración completada. Revisa la aplicación antes de dar de baja MySQL.\n";
} catch (Throwable $error) {
    if (isset($target) && $target->inTransaction()) {
        $target->rollBack();
    }
    // Solo el tipo y el código: el mensaje de PDO podría incluir valores de una fila.
    fwrite(STDERR, 'La migración falló y no se guardó nada (' . get_class($error) . ' ' . $error->getCode() . ").\n");
    exit(1);
}

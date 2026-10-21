<?php
declare(strict_types=1);

/**
 * Verificaciones de solo lectura sobre la base local y la configuración, para la
 * pantalla "Estado del sistema" (solo administradores). No consulta SIGES (solo
 * el worker lo hace) ni envía mensajes; no devuelve nombres ni celulares de
 * pacientes, solo totales. La comparación exacta contra SIGES sigue siendo
 * scripts/verify-sync.php.
 */
final class HealthCheckService
{
    private const AGENDA = "siges_cita_id IS NOT NULL AND status = 'RESERVADO'
        AND (appointment_date > CURRENT_DATE OR (appointment_date = CURRENT_DATE AND appointment_time >= LOCALTIME))";

    public function __construct(private PDO $database)
    {
    }

    /** @return list<array{group:string,name:string,status:string,detail:string}> status: ok | warn | fail | info */
    public function run(): array
    {
        $results = [];
        $add = static function (string $group, string $name, string $status, string $detail) use (&$results): void {
            $results[] = ['group' => $group, 'name' => $name, 'status' => $status, 'detail' => $detail];
        };

        $zone = (string) $this->database->query("SELECT current_setting('TimeZone')")->fetchColumn();
        $add('Base local', 'Conexión a PostgreSQL', 'ok', 'Responde correctamente.');
        $add('Base local', 'Zona horaria', $zone === 'America/Lima' ? 'ok' : 'warn', $zone . ($zone === 'America/Lima' ? '' : ' (se esperaba America/Lima)'));

        // Sincronización con SIGES
        $age = $this->database->query(
            "SELECT EXTRACT(EPOCH FROM (LOCALTIMESTAMP(0) - last_run_at))::int FROM siges_sync_state WHERE sync_key = 'appointments'"
        )->fetchColumn();
        if ($age === false || $age === null) {
            $add('Sincronización con SIGES', 'Latido del worker', 'fail', 'El worker nunca registró actividad. Revisa el contenedor siges-dashboard-sync.');
        } else {
            $age = (int) $age;
            $status = $age <= 15 ? 'ok' : ($age <= 60 ? 'warn' : 'fail');
            $add('Sincronización con SIGES', 'Latido del worker', $status, 'Última sincronización hace ' . $this->duration($age) . ($status === 'ok' ? '.' : ' (debería ser cada 3 s). Revisa el worker y la conexión a SIGES.'));
        }

        $agenda = $this->database->query(
            "SELECT COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE destination IS NULL) AS sin_celular,
                    COUNT(*) FILTER (WHERE sede_nombre = 'SEDE NO MAPEADA' OR sede_nombre IS NULL) AS sin_sede,
                    COUNT(*) FILTER (WHERE patient_name = 'PACIENTE NO IDENTIFICADO') AS sin_paciente,
                    COUNT(*) FILTER (WHERE doctor_name = '') AS sin_medico
             FROM appointments WHERE " . self::AGENDA
        )->fetch();
        $total = (int) $agenda['total'];
        $add('Datos de la agenda', 'Citas visibles en la agenda', $total > 0 ? 'ok' : 'warn', $total . ' citas RESERVADO por venir' . ($total > 0 ? '.' : '. La agenda está vacía; confirma que SIGES tenga citas.'));
        $noPhone = (int) $agenda['sin_celular'];
        $add('Datos de la agenda', 'Citas con celular detectado', $noPhone === 0 ? 'ok' : 'info', ($total - $noPhone) . ' de ' . $total . ' con celular; ' . $noPhone . ' sin número reconocible (se envían a mano).');
        $checks = [
            ['sin_sede', 'Citas con sede no mapeada', 'Consultorios de SIGES que el sistema no reconoce.'],
            ['sin_paciente', 'Citas sin paciente identificado', 'SIGES no devolvió nombre del paciente.'],
            ['sin_medico', 'Citas sin médico', 'La programación no trae médico.'],
        ];
        foreach ($checks as [$key, $label, $hint]) {
            $count = (int) $agenda[$key];
            $add('Datos de la agenda', $label, $count === 0 ? 'ok' : 'warn', $count === 0 ? 'Ninguna.' : $count . ' citas. ' . $hint);
        }
        $shared = (int) $this->database->query(
            "SELECT COUNT(*) FROM (SELECT destination FROM appointments WHERE " . self::AGENDA . " AND destination IS NOT NULL
                                   GROUP BY destination, appointment_date HAVING COUNT(DISTINCT patient_name) > 1) s"
        )->fetchColumn();
        $add('Datos de la agenda', 'Celulares compartidos por varios pacientes el mismo día', $shared === 0 ? 'ok' : 'info', $shared === 0 ? 'Ninguno.' : $shared . ' casos (puede ser una familia). Revísalo antes del envío masivo.');

        // Envíos
        $sends = $this->database->query(
            "SELECT COUNT(*) FILTER (WHERE status = 'sent') AS sent,
                    COUNT(*) FILTER (WHERE status = 'failed') AS failed,
                    COUNT(*) FILTER (WHERE status = 'pending' AND attempted_at < LOCALTIMESTAMP(0) - INTERVAL '10 minutes') AS stuck
             FROM reminder_messages WHERE attempted_at >= LOCALTIMESTAMP(0) - INTERVAL '24 hours'"
        )->fetch();
        $sent = (int) $sends['sent'];
        $failed = (int) $sends['failed'];
        $status = $failed === 0 ? 'ok' : ($failed > $sent ? 'fail' : 'warn');
        $add('Envíos de recordatorios', 'Últimas 24 horas', $status, $sent . ' aceptados por Gestionalo, ' . $failed . ' fallidos.' . ($failed > 0 ? ' Revisa CHATBOT_LINE_KEY y la conexión con Gestionalo.' : ''));
        $stuck = (int) $sends['stuck'];
        $add('Envíos de recordatorios', 'Intentos sin resultado (más de 10 min)', $stuck === 0 ? 'ok' : 'warn', $stuck === 0 ? 'Ninguno.' : $stuck . ' intentos quedaron en "pending"; el envío pudo cortarse a la mitad. Verifica en Gestionalo antes de reenviar.');
        $all = (int) $this->database->query("SELECT COUNT(*) FROM reminder_messages WHERE status = 'sent'")->fetchColumn();
        $add('Envíos de recordatorios', 'Historial total', 'info', $all . ' recordatorios aceptados desde el inicio.');

        // Configuración
        $configured = ChatbotApiClient::isConfigured();
        $add('Configuración', 'Gestionalo (CHATBOT_LINE_KEY)', $configured ? 'ok' : 'fail', $configured ? 'Configurado.' : 'Falta la serie; no se podrá enviar ningún mensaje.');
        $local = is_file(__DIR__ . '/../../config/local.php') ? require __DIR__ . '/../../config/local.php' : [];
        $token = (string) (getenv('CHATBOT_WEBHOOK_TOKEN') ?: ($local['CHATBOT_WEBHOOK_TOKEN'] ?? ''));
        $add('Configuración', 'Token del webhook', $token !== '' ? 'ok' : 'warn', $token !== '' ? 'Definido.' : 'No definido: el webhook de chats rechazará todo.');
        $debug = getenv('CHATBOT_WEBHOOK_DEBUG');
        $debugOn = in_array($debug === false ? ($local['CHATBOT_WEBHOOK_DEBUG'] ?? false) : $debug, [true, '1', 'true', 'on', 'yes'], true);
        $add('Configuración', 'Modo diagnóstico del webhook', $debugOn ? 'warn' : 'ok', $debugOn ? 'Encendido: apágalo cuando termines de descubrir el formato del payload.' : 'Apagado.');

        // Chats
        $chat = $this->database->query(
            "SELECT COUNT(*) FILTER (WHERE direction = 'inbound' AND read_at IS NULL) AS unread,
                    COUNT(*) FILTER (WHERE direction = 'inbound') AS inbound,
                    MAX(created_at) FILTER (WHERE direction = 'inbound') AS last_in
             FROM chat_messages"
        )->fetch();
        $inbound = (int) $chat['inbound'];
        $add('Chats', 'Mensajes entrantes', $inbound > 0 ? 'ok' : 'info', $inbound > 0 ? $inbound . ' recibidos; el último el ' . $chat['last_in'] . '; ' . $chat['unread'] . ' sin leer.' : 'Aún no ha llegado ninguno (webhook sin configurar o sin pruebas).');

        // Usuarios
        $users = $this->database->query(
            "SELECT COUNT(*) FILTER (WHERE active AND role = 'admin') AS admins, COUNT(*) FILTER (WHERE active AND role = 'operador') AS operators FROM users"
        )->fetch();
        $add('Usuarios', 'Administradores activos', (int) $users['admins'] >= 1 ? 'ok' : 'fail', (string) $users['admins']);
        $add('Usuarios', 'Operadores activos', 'info', (string) $users['operators']);

        return $results;
    }

    private function duration(int $seconds): string
    {
        if ($seconds < 120) {
            return $seconds . ' s';
        }
        if ($seconds < 7200) {
            return intdiv($seconds, 60) . ' min';
        }

        return intdiv($seconds, 3600) . ' h';
    }
}

<?php
declare(strict_types=1);

/**
 * Receptor de mensajes entrantes de Gestionalo (cuando un paciente responde
 * por WhatsApp). A diferencia del resto de public/, este archivo SÍ debe ser
 * alcanzable desde internet: Gestionalo necesita poder llamarlo. Por eso no
 * tiene el filtro de IP local; en su lugar se protege con un token secreto.
 *
 * Configuración en el panel de Gestionalo (URL del webhook de la línea):
 *   https://tu-dominio-publico/chat-webhook.php?token=TU_TOKEN_LARGO_Y_SECRETO
 *
 * El token se define en CHATBOT_WEBHOOK_TOKEN (variable de entorno o
 * config/local.php) y nunca se registra en logs ni se expone en respuestas.
 *
 * ADVERTENCIA: para que Gestionalo pueda llamar a esta URL, el servidor debe
 * ser accesible desde internet (dominio público con HTTPS, o un túnel). Esto
 * es distinto al resto de la aplicación, que se mantiene en 127.0.0.1. Revisa
 * docs/operacion-local.md antes de publicar este endpoint.
 *
 * ÚNICO BLOQUE A AJUSTAR: el formato exacto del payload de Gestionalo todavía
 * no está confirmado. Edita solo la sección marcada más abajo con los nombres
 * de campo reales; el resto (token, idempotencia, respuesta) no necesita cambios.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Repositories/ChatRepository.php';

header('Content-Type: application/json; charset=utf-8');

function chatWebhookToken(): string
{
    $localConfigPath = __DIR__ . '/../config/local.php';
    $local = is_file($localConfigPath) ? require $localConfigPath : [];

    return getenv('CHATBOT_WEBHOOK_TOKEN') ?: ($local['CHATBOT_WEBHOOK_TOKEN'] ?? '');
}

$expectedToken = chatWebhookToken();
$providedToken = (string) ($_GET['token'] ?? $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? '');
if ($expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Token inválido.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Método no permitido.']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

/*
 * ============================================================================
 * AJUSTA SOLO ESTE BLOQUE según el payload real que entregue Gestionalo.
 * $payload trae el cuerpo recibido ya decodificado (JSON o formulario).
 * Completa:
 *   - $destination: celular del paciente que escribió.
 *   - $body: texto del mensaje.
 *   - $providerMessageId: identificador único del mensaje si Gestionalo lo
 *     envía (evita guardarlo dos veces si reintentan la entrega). Puede
 *     quedar en null si no existe tal campo.
 * Los nombres usados abajo son una suposición razonable mientras se confirma
 * la documentación real de Gestionalo.
 * ============================================================================
 */
$destination = (string) ($payload['celular'] ?? $payload['from'] ?? $payload['phone'] ?? $payload['sender'] ?? '');
$body = (string) ($payload['mensaje'] ?? $payload['message'] ?? $payload['text'] ?? $payload['body'] ?? '');
$providerMessageId = isset($payload['id'])
    ? (string) $payload['id']
    : (isset($payload['message_id']) ? (string) $payload['message_id'] : null);
/* ============================================================================ */

$destination = preg_replace('/\D+/', '', $destination) ?? '';
$body = trim($body);

if ($destination === '' || $body === '') {
    error_log('Webhook de chat recibido sin celular o sin texto; revisa el formato del payload de Gestionalo.');
    http_response_code(422);
    echo json_encode(['error' => 'Faltan datos en el mensaje recibido.']);
    exit;
}
if (mb_strlen($body, 'UTF-8') > 4000) {
    $body = mb_substr($body, 0, 4000, 'UTF-8');
}

try {
    $repository = new ChatRepository(databaseConnection());
    $repository->insertInbound($destination, $body, $providerMessageId);
    echo json_encode(['ok' => true]);
} catch (Throwable $error) {
    error_log('No se pudo guardar un mensaje entrante de chat: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo guardar el mensaje.']);
}

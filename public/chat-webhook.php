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
 *
 * MODO DIAGNÓSTICO (CHATBOT_WEBHOOK_DEBUG=true en config/local.php o variable
 * de entorno, apagado por defecto): cuando el primer mensaje real de Gestionalo
 * llegue, registra en error_log únicamente los NOMBRES de los campos recibidos
 * y su tipo PHP (p. ej. celular:string, mensaje:string) para descubrir el
 * formato real sin depender de documentación externa. Nunca registra valores:
 * ni celulares, ni texto, ni el token. Apágalo una vez ajustado el bloque de
 * arriba.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Repositories/ChatRepository.php';

header('Content-Type: application/json; charset=utf-8');

function localWebhookConfig(): array
{
    static $local = null;
    if ($local === null) {
        $localConfigPath = __DIR__ . '/../config/local.php';
        $local = is_file($localConfigPath) ? require $localConfigPath : [];
    }

    return $local;
}

function chatWebhookToken(): string
{
    return getenv('CHATBOT_WEBHOOK_TOKEN') ?: (localWebhookConfig()['CHATBOT_WEBHOOK_TOKEN'] ?? '');
}

function webhookDebugEnabled(): bool
{
    $value = getenv('CHATBOT_WEBHOOK_DEBUG');
    if ($value === false) {
        $value = localWebhookConfig()['CHATBOT_WEBHOOK_DEBUG'] ?? false;
    }

    return in_array($value, [true, '1', 'true', 'on', 'yes'], true);
}

/** Solo nombres de campo y tipo PHP; nunca el valor (celular, texto, etc.). */
function payloadShape(mixed $value, int $depth = 0): mixed
{
    if (!is_array($value) || $depth > 4) {
        return gettype($value);
    }
    $shape = [];
    foreach ($value as $key => $item) {
        $shape[(string) $key] = payloadShape($item, $depth + 1);
    }

    return $shape;
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

if (webhookDebugEnabled()) {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '(ninguno)';
    error_log('Webhook debug — content-type: ' . $contentType . ' — estructura: ' . json_encode(payloadShape($payload), JSON_UNESCAPED_SLASHES));
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

<?php
declare(strict_types=1);

final class ChatbotApiException extends RuntimeException
{
    /** $detail: inicio de la respuesta de Gestionalo, con las secuencias largas de dígitos (celulares) ocultas. */
    public function __construct(public readonly int $httpStatus, public readonly string $detail = '')
    {
        parent::__construct('La API de Gestionalo respondió con HTTP ' . $httpStatus . '.');
    }

    /** 'no_whatsapp' cuando Gestionalo dice "No LID for user": el número no existe en WhatsApp. */
    public function failureReason(): ?string
    {
        return stripos($this->detail, 'No LID for user') !== false ? 'no_whatsapp' : null;
    }

    /** Respuesta de la API apta para mostrar o registrar: sin etiquetas, sin celulares y de longitud acotada. */
    public static function sanitizeDetail(string $body): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($body)) ?? '');
        $text = preg_replace('/\d{7,}/', '***', $text) ?? '';

        return mb_substr($text, 0, 200, 'UTF-8');
    }
}

/**
 * Cliente PHP para el endpoint de mensajes de Gestionalo.
 * No expone credenciales en excepciones ni registra el contenido enviado.
 */
final class ChatbotApiClient
{
    public function __construct(
        private string $baseUrl,
        private string $lineKey,
        private string $countryCode = '51',
        private int $timeoutSeconds = 30,
    ) {
        $this->baseUrl = rtrim($this->baseUrl, '/');
        if ($this->baseUrl === '' || $this->lineKey === '') {
            throw new InvalidArgumentException('Configura CHATBOT_BASE_URL y CHATBOT_LINE_KEY.');
        }
        if (!extension_loaded('curl')) {
            throw new RuntimeException('La extensión cURL de PHP es necesaria para conectar con el chatbot.');
        }
    }

    public static function fromEnvironment(): self
    {
        $config = self::configuration();

        return new self(
            $config['CHATBOT_BASE_URL'],
            $config['CHATBOT_LINE_KEY'],
            $config['CHATBOT_COUNTRY_CODE'],
        );
    }

    public static function isConfigured(): bool
    {
        $config = self::configuration();
        return $config['CHATBOT_LINE_KEY'] !== '';
    }

    private static function configuration(): array
    {
        $localConfigPath = __DIR__ . '/../../config/local.php';
        $local = is_file($localConfigPath) ? require $localConfigPath : [];

        return [
            'CHATBOT_BASE_URL' => getenv('CHATBOT_BASE_URL') ?: ($local['CHATBOT_BASE_URL'] ?? 'https://chatbot.gestionalo.pe'),
            'CHATBOT_LINE_KEY' => getenv('CHATBOT_LINE_KEY') ?: ($local['CHATBOT_LINE_KEY'] ?? ''),
            'CHATBOT_COUNTRY_CODE' => preg_replace('/\D+/', '', getenv('CHATBOT_COUNTRY_CODE') ?: ($local['CHATBOT_COUNTRY_CODE'] ?? '51')) ?: '51',
        ];
    }

    /** Acepta celular peruano local de 9 dígitos o un número internacional completo. */
    public function normalizeDestination(string $destination): string
    {
        $destination = preg_replace('/\D+/', '', $destination) ?? '';
        if (strlen($destination) === 9 && str_starts_with($destination, '9')) {
            $destination = $this->countryCode . $destination;
        }
        if (!preg_match('/^\d{8,15}$/', $destination)) {
            throw new InvalidArgumentException('Ingresa un celular válido, con 9 dígitos o código de país.');
        }

        return $destination;
    }

    /** Envía texto mediante el endpoint PHP /chat/sendmedia/{celular}?number={clave de línea}. */
    public function sendText(string $destination, string $message): array
    {
        $message = trim($message);
        if ($message === '') {
            throw new InvalidArgumentException('El mensaje no puede estar vacío.');
        }

        return $this->post($destination, ['message' => $message]);
    }

    /** Envía solo texto o, si hay imagen adjunta, la imagen con el texto como caption. */
    public function sendMessage(string $destination, string $message, ?MessageAttachment $attachment = null): array
    {
        if ($attachment === null) {
            return $this->sendText($destination, $message);
        }

        return $this->sendMedia(
            $destination,
            trim($message),
            $attachment->mimeType,
            $attachment->title,
            $attachment->base64,
        );
    }

    /** Envía un archivo ya codificado en base64. */
    public function sendMedia(
        string $destination,
        string $caption,
        string $mimeType,
        string $title,
        string $base64,
    ): array {
        if ($caption === '' || $title === '' || $mimeType === '') {
            throw new InvalidArgumentException('Caption, tipo MIME y título son obligatorios.');
        }
        if (base64_decode($base64, true) === false) {
            throw new InvalidArgumentException('El archivo debe estar codificado en base64 válido.');
        }

        return $this->post($destination, [
            'caption' => $caption,
            'type' => $mimeType,
            'title' => $title,
            'media' => $base64,
        ]);
    }

    /** Lee un archivo local y lo envía usando su MIME detectado. */
    public function sendFile(string $destination, string $caption, string $filePath): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new InvalidArgumentException('No se puede leer el archivo indicado.');
        }

        $contents = file_get_contents($filePath);
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);
        if ($contents === false || $mimeType === false) {
            throw new RuntimeException('No se pudo preparar el archivo para enviarlo.');
        }

        return $this->sendMedia($destination, $caption, $mimeType, basename($filePath), base64_encode($contents));
    }

    private function post(string $destination, array $payload): array
    {
        $destination = $this->normalizeDestination($destination);

        $url = $this->baseUrl . '/chat/sendmedia/' . rawurlencode($destination)
            . '?' . http_build_query(['number' => $this->lineKey], '', '&', PHP_QUERY_RFC3986);
        $body = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('No se pudo iniciar la conexión con el chatbot.');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: */*',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => max(1, $this->timeoutSeconds),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $responseBody = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($responseBody === false) {
            throw new RuntimeException('Falló la conexión con la API del chatbot: ' . $curlError);
        }
        if ($httpStatus < 200 || $httpStatus >= 300) {
            $detail = ChatbotApiException::sanitizeDetail((string) $responseBody);
            error_log('Gestionalo respondió HTTP ' . $httpStatus . ': ' . ($detail !== '' ? $detail : '(sin cuerpo)'));
            throw new ChatbotApiException($httpStatus, $detail);
        }

        return [
            'http_status' => $httpStatus,
            'response' => $responseBody,
        ];
    }
}

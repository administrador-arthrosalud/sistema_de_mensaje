<?php
declare(strict_types=1);

/**
 * Imagen opcional adjunta a un mensaje. Valida el tipo real del archivo (no la
 * extensión) y la mantiene solo en memoria: no se guarda en disco ni en la base de datos.
 */
final class MessageAttachment
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    private const ALLOWED_TYPES = ['image/png', 'image/jpeg'];

    private function __construct(
        public readonly string $mimeType,
        public readonly string $title,
        public readonly string $base64,
    ) {
    }

    /** Devuelve null cuando no se adjuntó ningún archivo. */
    public static function fromUpload(mixed $file): ?self
    {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $error = $file['error'];
        if (is_array($error)) {
            throw new InvalidArgumentException('Adjunta una sola imagen.');
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new InvalidArgumentException('La imagen es demasiado grande; el máximo es 5 MB.');
        }
        $path = (string) ($file['tmp_name'] ?? '');
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($path)) {
            throw new InvalidArgumentException('No se pudo recibir la imagen. Inténtalo de nuevo.');
        }

        $size = filesize($path);
        if ($size === false || $size === 0 || $size > self::MAX_BYTES) {
            throw new InvalidArgumentException('La imagen debe pesar como máximo 5 MB.');
        }
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mimeType, self::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException('Solo se permiten imágenes PNG o JPG.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('No se pudo leer la imagen adjunta.');
        }

        return new self($mimeType, self::title((string) ($file['name'] ?? '')), base64_encode($contents));
    }

    /** PHP descarta $_POST y $_FILES completos cuando la petición supera post_max_size. */
    public static function requestExceededPostLimit(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
            && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
            && $_POST === []
            && $_FILES === [];
    }

    private static function title(string $originalName): string
    {
        $title = pathinfo(basename($originalName), PATHINFO_FILENAME);
        $title = preg_replace('/[^\p{L}\p{N} ._-]+/u', '', $title) ?? '';
        $title = trim(mb_substr($title, 0, 80, 'UTF-8'));

        return $title !== '' ? $title : 'imagen';
    }
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/../Contracts/ChatbotMessengerInterface.php';

final class ChatController
{
    public function __construct(private ChatRepository $chats, private bool $chatbotConfigured)
    {
    }

    public function index(array $query): void
    {
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $formatTime = static fn (string $dateTime): string => (new DateTimeImmutable($dateTime))->format('d/m H:i');
        $csrfToken = (string) ($_SESSION['csrf_token'] ?? '');
        $chatbotConfigured = $this->chatbotConfigured;
        $notification = $_SESSION['notification'] ?? null;
        unset($_SESSION['notification']);

        $errorMessage = '';
        $conversations = [];
        $selected = preg_replace('/\D+/', '', (string) ($query['telefono'] ?? '')) ?? '';
        $thread = [];
        $selectedName = null;
        $chatUnread = 0;

        try {
            if ($selected !== '') {
                $thread = $this->chats->messagesForDestination($selected);
                $this->chats->markConversationRead($selected);
                $selectedName = $this->chats->guessPatientName($selected);
            }
            // Se cuenta después de marcar como leída la conversación abierta, para que
            // el contador y la lista reflejen el estado real al momento de mostrarse.
            $conversations = $this->chats->findConversations();
            $chatUnread = array_sum(array_map(static fn (array $row): int => (int) $row['unread'], $conversations));
        } catch (Throwable $error) {
            error_log('No se pudo cargar el módulo de chats: ' . get_class($error));
            $errorMessage = 'No se pudo conectar con la base de datos. Revisa la configuración y que PostgreSQL esté activo.';
        }

        require __DIR__ . '/../../views/chats.php';
    }

    public function send(array $input, array $files = [], ?ChatbotMessengerInterface $chatbot = null): never
    {
        if (MessageAttachment::requestExceededPostLimit()) {
            $this->redirectWith('error', 'La imagen es demasiado grande; el máximo es 5 MB.', '');
        }
        $this->verifyCsrf($input);

        $destination = preg_replace('/\D+/', '', (string) ($input['destination'] ?? '')) ?? '';
        if (!$this->chatbotConfigured) {
            $this->redirectWith('error', 'Configura CHATBOT_LINE_KEY antes de responder por el chat.', $destination);
        }
        if ($chatbot === null) {
            $this->redirectWith('error', 'No se pudo preparar el cliente de WhatsApp.', $destination);
        }

        $message = trim((string) ($input['message'] ?? ''));
        if ($message === '' || mb_strlen($message, 'UTF-8') > 1000) {
            $this->redirectWith('error', 'Escribe un mensaje de hasta 1000 caracteres.', $destination);
        }

        $attemptId = null;
        $apiAccepted = false;
        try {
            $attachment = MessageAttachment::fromUpload($files['attachment'] ?? null);
            $normalized = $chatbot->normalizeDestination($destination);
            // La imagen no se guarda en la base de datos; el hilo solo registra su título.
            $storedBody = $attachment === null ? $message : '[Imagen: ' . $attachment->title . "]\n" . $message;
            $attemptId = $this->chats->createOutboundAttempt($normalized, $storedBody);
            $result = $chatbot->sendMessage($normalized, $message, $attachment);
            $apiAccepted = true;
            $this->chats->finishOutboundAttempt($attemptId, 'sent', (int) $result['http_status']);
            $this->redirectWith('success', '', $normalized, false);
        } catch (InvalidArgumentException $error) {
            $this->markFailed($attemptId);
            $this->redirectWith('error', $error->getMessage(), $destination);
        } catch (ChatbotApiException $error) {
            $this->markFailed($attemptId, $error->httpStatus);
            if (in_array($error->httpStatus, [401, 403], true)) {
                $this->redirectWith('error', 'Gestionalo rechazó la solicitud. Verifica la clave autorizada de la línea.', $destination);
            }
            $this->redirectWith('error', 'Gestionalo respondió con HTTP ' . $error->httpStatus . '.', $destination);
        } catch (Throwable $error) {
            error_log('Falló el envío de una respuesta de chat: ' . get_class($error));
            if ($apiAccepted) {
                $this->redirectWith('error', 'Gestionalo aceptó el mensaje, pero no se pudo guardar el resultado.', $destination);
            }
            $this->markFailed($attemptId);
            $this->redirectWith('error', 'No se pudo enviar. Revisa la conexión y la configuración de la API.', $destination);
        }
    }

    private function markFailed(?int $attemptId, ?int $httpStatus = null): void
    {
        if ($attemptId === null) {
            return;
        }
        try {
            $this->chats->finishOutboundAttempt($attemptId, 'failed', $httpStatus);
        } catch (Throwable) {
            error_log('No se pudo actualizar el intento de respuesta de chat.');
        }
    }

    private function verifyCsrf(array $input): void
    {
        $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
        $submittedToken = (string) ($input['csrf_token'] ?? '');
        if ($sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
            $this->redirectWith('error', 'La sesión expiró. Recarga el módulo e inténtalo de nuevo.', (string) ($input['destination'] ?? ''));
        }
    }

    private function redirectWith(string $type, string $message, string $destination, bool $withNotification = true): never
    {
        if ($withNotification) {
            $_SESSION['notification'] = ['type' => $type, 'message' => $message];
        }
        $target = 'chats.php' . ($destination !== '' ? '?telefono=' . rawurlencode($destination) : '');
        header('Location: ' . $target, true, 303);
        exit;
    }
}

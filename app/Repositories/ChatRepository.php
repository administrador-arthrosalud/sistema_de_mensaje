<?php
declare(strict_types=1);

final class ChatRepository
{
    public function __construct(private PDO $database)
    {
    }

    public function conversationsVersion(): string
    {
        $statement = $this->database->query(
            "SELECT COUNT(*) AS total, COALESCE(MAX(id), 0) AS max_id,
                    COALESCE(to_char(MAX(created_at), 'YYYY-MM-DD HH24:MI:SS'), '') AS last_at
             FROM chat_messages"
        );
        $version = $statement->fetch();

        return hash('sha256', implode('|', [
            (string) ($version['total'] ?? 0),
            (string) ($version['max_id'] ?? 0),
            (string) ($version['last_at'] ?? ''),
        ]));
    }

    public function unreadTotal(): int
    {
        $statement = $this->database->query(
            "SELECT COUNT(*) FROM chat_messages WHERE direction = 'inbound' AND read_at IS NULL"
        );

        return (int) $statement->fetchColumn();
    }

    /** Conversaciones agrupadas por celular con el último mensaje y los no leídos. */
    public function findConversations(): array
    {
        return $this->database->query(
            "SELECT c.destination, c.unread, m.body AS last_body, m.direction AS last_direction,
                    m.created_at AS last_at, m.status AS last_status,
                    (SELECT rm.patient_name FROM reminder_messages rm
                     WHERE rm.destination = c.destination ORDER BY rm.id DESC LIMIT 1) AS patient_name
             FROM (
                 SELECT destination, MAX(id) AS last_id,
                        SUM(CASE WHEN direction = 'inbound' AND read_at IS NULL THEN 1 ELSE 0 END) AS unread
                 FROM chat_messages
                 GROUP BY destination
             ) c
             JOIN chat_messages m ON m.id = c.last_id
             ORDER BY m.created_at DESC, m.id DESC"
        )->fetchAll();
    }

    public function guessPatientName(string $destination): ?string
    {
        $statement = $this->database->prepare(
            'SELECT patient_name FROM reminder_messages WHERE destination = :destination ORDER BY id DESC LIMIT 1'
        );
        $statement->execute([':destination' => $destination]);
        $name = $statement->fetchColumn();

        return $name === false ? null : (string) $name;
    }

    public function messagesForDestination(string $destination, int $limit = 200): array
    {
        $statement = $this->database->prepare(
            'SELECT id, direction, body, status, http_status, created_at
             FROM chat_messages
             WHERE destination = :destination
             ORDER BY id ASC
             LIMIT :limit'
        );
        $statement->bindValue(':destination', $destination, PDO::PARAM_STR);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /** Mensajes nuevos de una conversación, para el sondeo del hilo abierto. */
    public function messagesAfter(string $destination, int $afterId): array
    {
        $statement = $this->database->prepare(
            'SELECT id, direction, body, status, http_status, created_at
             FROM chat_messages
             WHERE destination = :destination AND id > :after_id
             ORDER BY id ASC'
        );
        $statement->execute([':destination' => $destination, ':after_id' => $afterId]);

        return $statement->fetchAll();
    }

    public function markConversationRead(string $destination): void
    {
        $statement = $this->database->prepare(
            "UPDATE chat_messages SET read_at = LOCALTIMESTAMP(0)
             WHERE destination = :destination AND direction = 'inbound' AND read_at IS NULL"
        );
        $statement->execute([':destination' => $destination]);
    }

    /**
     * Inserta un mensaje entrante. Es idempotente cuando Gestionalo entrega un
     * identificador de mensaje: un reintento del mismo webhook no duplica la fila.
     */
    public function insertInbound(string $destination, string $body, ?string $providerMessageId): int
    {
        // Un NULL nunca entra en conflicto, así que los mensajes sin id siempre se insertan.
        $statement = $this->database->prepare(
            "INSERT INTO chat_messages (destination, direction, body, provider_message_id, status)
             VALUES (:destination, 'inbound', :body, :provider_message_id, 'received')
             ON CONFLICT (provider_message_id) DO NOTHING
             RETURNING id"
        );
        $statement->execute([
            ':destination' => $destination,
            ':body' => $body,
            ':provider_message_id' => $providerMessageId,
        ]);

        return (int) $statement->fetchColumn();
    }

    public function createOutboundAttempt(string $destination, string $body): int
    {
        $statement = $this->database->prepare(
            "INSERT INTO chat_messages (destination, direction, body, status)
             VALUES (:destination, 'outbound', :body, 'pending')
             RETURNING id"
        );
        $statement->execute([':destination' => $destination, ':body' => $body]);

        return (int) $statement->fetchColumn();
    }

    public function finishOutboundAttempt(int $id, string $status, ?int $httpStatus = null): void
    {
        if (!in_array($status, ['sent', 'failed'], true)) {
            throw new InvalidArgumentException('El resultado del envío no es válido.');
        }
        $statement = $this->database->prepare(
            "UPDATE chat_messages SET status = :status, http_status = :http_status
             WHERE id = :id AND direction = 'outbound' AND status = 'pending'"
        );
        $statement->execute([':status' => $status, ':http_status' => $httpStatus, ':id' => $id]);
    }
}

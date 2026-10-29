<?php
declare(strict_types=1);

/** Cola compacta: agrupa el mismo error en ventanas de cinco minutos. */
final class SystemErrorAlertRepository
{
    public function __construct(private PDO $database)
    {
    }

    public function record(string $component, string $errorCode, ?int $httpStatus = null): void
    {
        $component = self::safeCode($component, 'app');
        $errorCode = self::safeCode($errorCode, 'unknown_error');
        $httpStatus = $httpStatus !== null && $httpStatus >= 100 && $httpStatus <= 599 ? $httpStatus : 0;

        $statement = $this->database->prepare(
            "INSERT INTO system_error_alerts
                (bucket_at, component, error_code, http_status, first_seen_at, last_seen_at)
             VALUES (
                date_trunc('hour', LOCALTIMESTAMP(0))
                    + (floor(EXTRACT(MINUTE FROM LOCALTIMESTAMP(0)) / 5)::integer * INTERVAL '5 minutes'),
                :component, :error_code, :http_status, LOCALTIMESTAMP(0), LOCALTIMESTAMP(0)
             )
             ON CONFLICT (bucket_at, component, error_code, http_status) DO UPDATE
             SET occurrences = system_error_alerts.occurrences + 1,
                 last_seen_at = LOCALTIMESTAMP(0),
                 status = 'pending',
                 next_attempt_at = LEAST(system_error_alerts.next_attempt_at, LOCALTIMESTAMP(0))"
        );
        $statement->execute([
            ':component' => $component,
            ':error_code' => $errorCode,
            ':http_status' => $httpStatus,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function pending(int $limit = 200): array
    {
        $statement = $this->database->prepare(
            "SELECT id, bucket_at, component, error_code, http_status, occurrences, first_seen_at, last_seen_at
             FROM system_error_alerts
             WHERE status = 'pending'
               AND next_attempt_at <= LOCALTIMESTAMP(0)
               AND bucket_at < date_trunc('hour', LOCALTIMESTAMP(0))
                    + (floor(EXTRACT(MINUTE FROM LOCALTIMESTAMP(0)) / 5)::integer * INTERVAL '5 minutes')
             ORDER BY bucket_at ASC, component ASC, error_code ASC
             LIMIT :limit"
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /** @param list<int> $ids */
    public function markSent(array $ids): void
    {
        $this->updateIds($ids, "status = 'sent', sent_at = LOCALTIMESTAMP(0)");
    }

    /** @param list<int> $ids */
    public function deferRetry(array $ids): void
    {
        $this->updateIds(
            $ids,
            "attempts = attempts + 1, next_attempt_at = LOCALTIMESTAMP(0) + INTERVAL '5 minutes'"
        );
    }

    /** @param list<int> $ids */
    private function updateIds(array $ids, string $setClause): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return;
        }

        $parameters = [];
        $placeholders = [];
        foreach ($ids as $index => $id) {
            $placeholder = ':id_' . $index;
            $placeholders[] = $placeholder;
            $parameters[$placeholder] = $id;
        }
        $statement = $this->database->prepare(
            'UPDATE system_error_alerts SET ' . $setClause . ' WHERE id IN (' . implode(', ', $placeholders) . ')'
        );
        $statement->execute($parameters);
    }

    private static function safeCode(string $value, string $fallback): string
    {
        $value = strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', $value) ?? '');
        $value = trim($value, '_');

        return $value === '' ? $fallback : mb_substr($value, 0, 48, 'UTF-8');
    }
}

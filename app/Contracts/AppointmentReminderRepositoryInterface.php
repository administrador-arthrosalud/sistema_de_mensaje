<?php
declare(strict_types=1);

interface AppointmentReminderRepositoryInterface
{
    public function createReminderAttempt(array $appointment, string $destination, bool $auto = false): int;

    public function finishReminderAttempt(
        int $id,
        string $status,
        ?int $httpStatus = null,
        ?string $failureReason = null,
    ): void;

    public function countSentReminders(array $filters): int;

    public function findSentReminders(array $filters, int $limit, int $offset): array;
}

<?php
declare(strict_types=1);

interface AppointmentRepositoryInterface
{
    public function countPending(array $filters): int;

    public function pendingVersion(): string;

    public function summarizePending(array $filters): array;

    public function findPending(array $filters, int $limit, int $offset): array;

    public function findPendingById(int $id): ?array;

    public function findReadyForBulkReminder(string $date, bool $auto = false): array;

    public function countNoWhatsapp(string $date): int;

    public function countNeedingManualDestination(string $date): int;

    public function findReadyForBulkReminderById(int $id, bool $auto = false): ?array;
}

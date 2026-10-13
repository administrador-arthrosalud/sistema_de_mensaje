<?php
declare(strict_types=1);

final class DashboardService
{
    private const PAGE_SIZE = 25;

    public function __construct(private AppointmentRepository $appointments)
    {
    }

    public function dashboard(array $query): array
    {
        $search = $query['q'] ?? '';
        $filters = [
            'search' => is_string($search) ? trim($search) : '',
            'date_from' => $this->validDate($query['desde'] ?? ''),
            'date_to' => $this->validDate($query['hasta'] ?? ''),
        ];
        if ($filters['date_from'] !== '' && $filters['date_to'] !== '' && $filters['date_from'] > $filters['date_to']) {
            [$filters['date_from'], $filters['date_to']] = [$filters['date_to'], $filters['date_from']];
        }

        $total = $this->appointments->countPending($filters);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $requestedPage = filter_var($query['pagina'] ?? 1, FILTER_VALIDATE_INT);
        $page = max(1, min($requestedPage === false ? 1 : $requestedPage, $pages));

        return [
            'filters' => $filters,
            'version' => $this->appointments->pendingVersion(),
            'summary' => $this->appointments->summarizePending($filters),
            'appointments' => $this->appointments->findPending($filters, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE),
            'pagination' => ['page' => $page, 'pages' => $pages, 'page_size' => self::PAGE_SIZE],
        ];
    }

    private function validDate(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '';
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $value : '';
    }
}

<?php
declare(strict_types=1);

final class DashboardController
{
    public function __construct(private ?DashboardService $dashboardService, private string $startupError = '')
    {
    }

    public function index(array $query): void
    {
        try {
            if ($this->dashboardService === null) {
                throw new RuntimeException($this->startupError);
            }
            $dashboard = $this->dashboardService->dashboard($query);
            $errorMessage = '';
        } catch (Throwable $error) {
            error_log("No se pudo cargar la agenda: " . get_class($error));
            $dashboard = [
                'filters' => ['search' => '', 'sede' => '', 'date_from' => '', 'date_to' => ''],
                'version' => '',
                'summary' => ['total' => 0, 'doctors' => 0, 'sites' => 0, 'with_note' => 0, 'with_reason' => 0],
                'appointments' => [],
                'pagination' => ['page' => 1, 'pages' => 1, 'page_size' => 25],
            ];
            $errorMessage = 'No se pudo conectar con la base de datos. Revisa la configuración y que PostgreSQL esté activo.';
        }

        $filters = $dashboard['filters'];
        $dataVersion = $dashboard['version'] ?? '';
        $summary = $dashboard['summary'];
        $appointments = $dashboard['appointments'];
        $pagination = $dashboard['pagination'];
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $formatDate = static fn (string $date): string => (new DateTimeImmutable($date))->format('d/m/Y');
        $paginationQuery = array_filter([
            'q' => $filters['search'],
            'sede' => $filters['sede'],
            'desde' => $filters['date_from'],
            'hasta' => $filters['date_to'],
        ], static fn (string $value): bool => $value !== '');
        $sedes = DashboardService::sedes();
        $csrfToken = (string) ($_SESSION['csrf_token'] ?? '');
        $chatbotConfigured = ChatbotApiClient::isConfigured();
        $notification = $_SESSION['notification'] ?? null;
        unset($_SESSION['notification']);
        try {
            $chatUnread = (new ChatRepository(databaseConnection()))->unreadTotal();
        } catch (Throwable) {
            $chatUnread = 0;
        }

        require __DIR__ . '/../../views/dashboard.php';
    }
}



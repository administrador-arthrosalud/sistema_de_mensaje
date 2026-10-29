<?php
declare(strict_types=1);

/**
 * Genera el mismo texto de recordatorio que arma public/assets/js/app.js
 * (función buildReminderMessage) para usarlo donde no hay una persona
 * escribiendo en el formulario, como el envío masivo. Si cambias la
 * plantilla en un lado, cámbiala también en el otro.
 */
final class ReminderMessageBuilder
{
    private const CLINIC_NAME = 'Clínica Arthrosalud';
    private const SEDE_ADDRESSES = [
        'LIMA' => 'Jr. Sánchez Pinillos 188, Lima 15082',
        'LINCE' => 'Av. P.º de la República 2772, Lince 15046',
        'LINCE - CONSULTORIO 3' => 'Jr. Joaquín Bernal 344, Lince',
    ];
    private const SEDE_MAP_LINKS = [
        'LIMA' => 'https://maps.app.goo.gl/uuT3UsKLBS57XV4Z9',
        'LINCE' => 'https://maps.app.goo.gl/GJPnx7zYda94qndy5',
        'LINCE - CONSULTORIO 3' => 'https://maps.app.goo.gl/SSGKyWAWZuA3S9xC7',
    ];
    private const LOWER_WORDS = ['de', 'del', 'la', 'las', 'los', 'y'];

    public static function build(
        string $patientName,
        string $appointmentDate,
        string $appointmentTime,
        string $doctorName = '',
        ?string $sede = null,
    ): string {
        $lines = [
            '*Hola, ' . self::titleCase($patientName) . '.*',
            'Le recordamos que tiene una cita programada en la ' . self::CLINIC_NAME . '.',
            '',
            '📅*Fecha:* ' . (new DateTimeImmutable($appointmentDate))->format('d/m/Y'),
            '🕐*Hora:* ' . self::time12h($appointmentTime),
        ];
        if (trim($doctorName) !== '') {
            $lines[] = '🩺*Médico:* Dr(a). ' . self::titleCase($doctorName);
        }
        $sedeLine = self::sedeLine($sede);
        if ($sedeLine !== null) {
            $lines[] = $sedeLine;
        }

        return implode("\n", $lines);
    }

    private static function sedeLine(?string $sede): ?string
    {
        $sede = trim((string) $sede);
        if ($sede === '' || $sede === 'SEDE NO MAPEADA') {
            return null;
        }
        $key = mb_strtoupper($sede, 'UTF-8');
        $address = self::SEDE_ADDRESSES[$key] ?? null;

        return $address !== null
            ? '📍*Sede ' . $key . ':* ' . $address . "\n🗺️*Mapa:* " . self::SEDE_MAP_LINKS[$key]
            : '📍*Sede:* ' . self::titleCase($sede);
    }

    private static function titleCase(string $value): string
    {
        $words = preg_split('/\s+/u', mb_strtolower(trim($value), 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_map(
            static function (string $word, int $index): string {
                if ($index > 0 && in_array($word, self::LOWER_WORDS, true)) {
                    return $word;
                }

                return mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($word, 1, null, 'UTF-8');
            },
            $words,
            array_keys($words),
        ));
    }

    private static function time12h(string $value): string
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})/', $value, $match)) {
            return $value;
        }
        $hours = (int) $match[1];

        return sprintf('%d:%s %s', $hours % 12 ?: 12, $match[2], $hours < 12 ? 'a. m.' : 'p. m.');
    }
}

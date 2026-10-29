<?php
declare(strict_types=1);

/**
 * Calendario del envío automático de la víspera. Lógica pura (sin base de datos ni red) para poder
 * probarla con fechas fijas.
 *
 * Cada día a las 17:00 (hora de la clínica) se avisan las citas del día siguiente. Las del lunes se
 * avisan el sábado y el domingo, así que el sábado a las 17:00 también se cubre el domingo y el lunes.
 * Desde las 17:00 el worker puede repetir la revisión para citas que aparecieron tarde; lo ya enviado
 * nunca se duplica porque el repositorio excluye las citas con un recordatorio `sent`.
 */
final class AutoReminderScheduler
{
    public const SEND_HOUR = 17;

    /** @return list<string> fechas (Y-m-d) cuyas citas toca avisar ahora; vacío antes de las 17:00. */
    public static function targetDates(DateTimeImmutable $now): array
    {
        if ((int) $now->format('G') < self::SEND_HOUR) {
            return [];
        }
        $today = $now->setTime(0, 0);
        $dates = [$today->modify('+1 day')];
        if ($today->format('N') === '6') { // sábado: también el lunes
            $dates[] = $today->modify('+2 days');
        }

        return array_map(static fn (DateTimeImmutable $date): string => $date->format('Y-m-d'), $dates);
    }
}

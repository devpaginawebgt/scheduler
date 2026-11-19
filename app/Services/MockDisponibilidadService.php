<?php

namespace App\Services;

use App\Contracts\DisponibilidadServiceContract;
use Carbon\CarbonImmutable;

class MockDisponibilidadService implements DisponibilidadServiceContract
{
    /** Business hours: 09:00–18:00, lunch break 13:00–14:00, 60-min slots, Mon–Fri. */
    private const SLOT_HOURS = [9, 10, 11, 12, 14, 15, 16, 17];

    /** Deterministic "occupied" slots: (weekday 1=Mon–5=Fri) => [hour, ...] */
    private const MOCK_BUSY = [
        1 => [10, 15],
        2 => [9, 14],
        3 => [11, 16],
        4 => [9, 12, 17],
        5 => [10, 14],
    ];

    private const WINDOW_DAYS = 20;

    public function rango(): array
    {
        $tz = config('whatsapp.flow_timezone', 'America/Guatemala');
        $today = CarbonImmutable::now($tz);
        $minFecha = $this->nextBusinessDay($today);
        $maxFecha = $today->addDays(self::WINDOW_DAYS);

        return [
            'min_fecha' => $minFecha->toDateString(),
            'max_fecha' => $maxFecha->toDateString(),
            'fechas_bloqueadas' => $this->blockedDates($minFecha, $maxFecha),
        ];
    }

    public function horas(string $fecha): array
    {
        $tz = config('whatsapp.flow_timezone', 'America/Guatemala');
        $date = CarbonImmutable::createFromFormat('Y-m-d', $fecha, $tz);

        if (! $date || ! $this->isBusinessDay($date)) {
            return [];
        }

        $weekday = (int) $date->dayOfWeekIso; // 1=Mon … 5=Fri
        $busy = self::MOCK_BUSY[$weekday] ?? [];

        $slots = [];
        foreach (self::SLOT_HOURS as $hour) {
            if (in_array($hour, $busy, true)) {
                continue;
            }

            $dt = $date->setTime($hour, 0);
            $slots[] = [
                'id' => $dt->format('Y-m-d\TH:i'),
                'title' => $this->formatHour($dt),
            ];
        }

        return $slots;
    }

    private function nextBusinessDay(CarbonImmutable $from): CarbonImmutable
    {
        $day = $from->addDay();
        while (! $this->isBusinessDay($day)) {
            $day = $day->addDay();
        }

        return $day;
    }

    private function isBusinessDay(CarbonImmutable $date): bool
    {
        return $date->isWeekday();
    }

    /** @return list<string> */
    private function blockedDates(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $blocked = [];
        $cursor = $from->copy();

        while ($cursor->lte($to)) {
            if (! $this->isBusinessDay($cursor) || empty($this->horas($cursor->toDateString()))) {
                // Only include dates inside the window that are business days with no slots
                if ($this->isBusinessDay($cursor)) {
                    $blocked[] = $cursor->toDateString();
                }
            }
            $cursor = $cursor->addDay();
        }

        return $blocked;
    }

    private function formatHour(CarbonImmutable $dt): string
    {
        // Locale-aware 12-hour format in Spanish: "10:00 a. m." / "3:00 p. m."
        $hour = (int) $dt->format('g');
        $minutes = $dt->format('i');
        $ampm = $dt->format('A') === 'AM' ? 'a. m.' : 'p. m.';

        return "{$hour}:{$minutes} {$ampm}";
    }
}

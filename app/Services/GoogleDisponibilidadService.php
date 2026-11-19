<?php

namespace App\Services;

use App\Contracts\DisponibilidadServiceContract;
use App\Models\BlockedDate;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class GoogleDisponibilidadService implements DisponibilidadServiceContract
{
    /** Business hours: 08:00–18:00, lunch break 13:00–14:00, 60-min slots, Mon–Fri. */
    private const SLOT_HOURS = [8, 9, 10, 11, 12, 14, 15, 16, 17];

    private const WINDOW_DAYS = 20;

    public function __construct(
        private readonly CalendarService $calendar,
    ) {}

    public function rango(): array
    {
        $tz = config('whatsapp.flow_timezone', 'America/Guatemala');
        $today = CarbonImmutable::now($tz);
        $minDate = $this->nextBusinessDay($today);
        $maxDate = $today->addDays(self::WINDOW_DAYS);

        // Source 1: DB blocked dates
        $dbBlocked = BlockedDate::whereBetween('date', [
            $minDate->toDateString(),
            $maxDate->toDateString(),
        ])->pluck('date')->map(fn ($d) => (string) $d)->all();

        // Source 2: All-day calendar events
        $calendarBlocked = $this->calendar
            ->getEvents(
                Carbon::parse($minDate->toDateString(), $tz),
                Carbon::parse($maxDate->toDateString(), $tz),
            )
            ->filter(fn (array $event) => $event['all_day'] === true)
            ->map(fn (array $event) => Carbon::parse($event['start'])->toDateString())
            ->values()
            ->all();

        // Source 3: Fully-booked days — single FreeBusy call for the whole window
        $windowBusySlots = $this->calendar->getBusySlots(
            Carbon::parse($minDate->toDateString(), $tz)->startOfDay(),
            Carbon::parse($maxDate->toDateString(), $tz)->endOfDay(),
        );

        $fullyBookedDates = [];
        $cursor = $minDate;

        while ($cursor->lte($maxDate)) {
            if ($cursor->isWeekday()) {
                $dateStr = $cursor->toDateString();

                if (! in_array($dateStr, $dbBlocked, true) && ! in_array($dateStr, $calendarBlocked, true)) {
                    if (empty($this->computeSlots($cursor, $windowBusySlots))) {
                        $fullyBookedDates[] = $dateStr;
                    }
                }
            }

            $cursor = $cursor->addDay();
        }

        $blockedDates = collect([...$dbBlocked, ...$calendarBlocked, ...$fullyBookedDates])
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
            'min_fecha' => $minDate->toDateString(),
            'max_fecha' => $maxDate->toDateString(),
            'fechas_bloqueadas' => $blockedDates,
        ];
    }

    public function horas(string $fecha): array
    {
        $tz = config('whatsapp.flow_timezone', 'America/Guatemala');
        $date = CarbonImmutable::createFromFormat('Y-m-d', $fecha, $tz);

        if (! $date || ! $date->isWeekday()) {
            return [];
        }

        if (BlockedDate::where('date', $fecha)->exists()) {
            return [];
        }

        $busySlots = $this->calendar->getBusySlots(
            Carbon::parse($fecha, $tz)->startOfDay(),
            Carbon::parse($fecha, $tz)->endOfDay(),
        );

        return $this->computeSlots($date, $busySlots);
    }

    /** @return list<array{id: string, title: string}> */
    private function computeSlots(CarbonImmutable $date, Collection $busySlots): array
    {
        $tz = config('whatsapp.flow_timezone', 'America/Guatemala');
        $slots = [];

        foreach (self::SLOT_HOURS as $hour) {
            $slotStart = $date->setTime($hour, 0)->setTimezone($tz);
            $slotEnd = $slotStart->addHour();

            if ($this->slotOverlapsBusy($slotStart, $slotEnd, $busySlots)) {
                continue;
            }

            $slots[] = [
                'id' => $slotStart->format('Y-m-d\TH:i'),
                'title' => $this->formatHour($slotStart),
            ];
        }

        return $slots;
    }

    private function slotOverlapsBusy(
        CarbonImmutable $slotStart,
        CarbonImmutable $slotEnd,
        Collection $busySlots,
    ): bool {
        foreach ($busySlots as $busy) {
            $busyStart = CarbonImmutable::parse($busy['start']);
            $busyEnd = CarbonImmutable::parse($busy['end']);

            if ($slotStart->lt($busyEnd) && $slotEnd->gt($busyStart)) {
                return true;
            }
        }

        return false;
    }

    private function nextBusinessDay(CarbonImmutable $from): CarbonImmutable
    {
        $day = $from->addDay();

        while (! $day->isWeekday()) {
            $day = $day->addDay();
        }

        return $day;
    }

    private function formatHour(CarbonImmutable $dt): string
    {
        $hour = (int) $dt->format('g');
        $minutes = $dt->format('i');
        $ampm = $dt->format('A') === 'AM' ? 'a. m.' : 'p. m.';

        return "{$hour}:{$minutes} {$ampm}";
    }
}

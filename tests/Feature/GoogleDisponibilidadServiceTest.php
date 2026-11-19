<?php

use App\Models\BlockedDate;
use App\Services\CalendarService;
use App\Services\GoogleDisponibilidadService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------

function nextWeekday(int $n = 1): string
{
    $tz = config('whatsapp.flow_timezone', 'America/Guatemala');
    $date = CarbonImmutable::now($tz);

    for ($i = 0; $i < $n; $i++) {
        $date = $date->addDay();
        while (! $date->isWeekday()) {
            $date = $date->addDay();
        }
    }

    return $date->toDateString();
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function (): void {
    $this->calendarMock = Mockery::mock(CalendarService::class);
    $this->service = new GoogleDisponibilidadService($this->calendarMock);
});

// ---------------------------------------------------------------------------
// rango()
// ---------------------------------------------------------------------------

it('rango() returns correct window boundaries', function (): void {
    $this->calendarMock->shouldReceive('getEvents')->once()->andReturn(collect());
    $this->calendarMock->shouldReceive('getBusySlots')->once()->andReturn(collect());

    $result = $this->service->rango();

    $tz = config('whatsapp.flow_timezone', 'America/Guatemala');
    $today = CarbonImmutable::now($tz);
    $expectedMin = $today->addDay();
    while (! $expectedMin->isWeekday()) {
        $expectedMin = $expectedMin->addDay();
    }

    expect($result['min_fecha'])->toBe($expectedMin->toDateString())
        ->and($result['max_fecha'])->toBe($today->addDays(20)->toDateString())
        ->and($result)->toHaveKey('fechas_bloqueadas');
});

it('rango() includes DB-blocked date in fechas_bloqueadas', function (): void {
    $blockedDate = nextWeekday(1);
    BlockedDate::create(['date' => $blockedDate, 'reason' => 'Public holiday']);

    $this->calendarMock->shouldReceive('getEvents')->once()->andReturn(collect());
    $this->calendarMock->shouldReceive('getBusySlots')->once()->andReturn(collect());

    expect($this->service->rango()['fechas_bloqueadas'])->toContain($blockedDate);
});

it('rango() includes all-day Calendar event date in fechas_bloqueadas', function (): void {
    $blockedDate = nextWeekday(2);

    $this->calendarMock->shouldReceive('getEvents')->once()->andReturn(collect([
        [
            'id' => 'e1',
            'title' => 'Closed',
            'start' => $blockedDate.'T00:00:00+00:00',
            'end' => $blockedDate.'T23:59:59+00:00',
            'all_day' => true,
            'description' => null,
            'location' => null,
        ],
    ]));
    $this->calendarMock->shouldReceive('getBusySlots')->once()->andReturn(collect());

    expect($this->service->rango()['fechas_bloqueadas'])->toContain($blockedDate);
});

it('rango() includes fully-booked day in fechas_bloqueadas', function (): void {
    $bookedDate = nextWeekday(3);
    $tz = config('whatsapp.flow_timezone', 'America/Guatemala');

    $busySlots = collect([[
        'start' => Carbon::parse($bookedDate.' 08:00', $tz)->toRfc3339String(),
        'end' => Carbon::parse($bookedDate.' 18:00', $tz)->toRfc3339String(),
    ]]);

    $this->calendarMock->shouldReceive('getEvents')->once()->andReturn(collect());
    $this->calendarMock->shouldReceive('getBusySlots')->once()->andReturn($busySlots);

    expect($this->service->rango()['fechas_bloqueadas'])->toContain($bookedDate);
});

// ---------------------------------------------------------------------------
// horas()
// ---------------------------------------------------------------------------

it('horas() returns empty array for weekend', function (): void {
    $tz = config('whatsapp.flow_timezone', 'America/Guatemala');
    $saturday = CarbonImmutable::now($tz)->next(Carbon::SATURDAY)->toDateString();

    $this->calendarMock->shouldNotReceive('getBusySlots');

    expect($this->service->horas($saturday))->toBe([]);
});

it('horas() returns empty array for DB-blocked date', function (): void {
    $blockedDate = nextWeekday(1);
    BlockedDate::create(['date' => $blockedDate]);

    $this->calendarMock->shouldNotReceive('getBusySlots');

    expect($this->service->horas($blockedDate))->toBe([]);
});

it('horas() filters out slots overlapping busy periods', function (): void {
    $fecha = nextWeekday(1);
    $tz = config('whatsapp.flow_timezone', 'America/Guatemala');

    $busySlots = collect([
        [
            'start' => Carbon::parse($fecha.' 10:00', $tz)->toRfc3339String(),
            'end' => Carbon::parse($fecha.' 11:00', $tz)->toRfc3339String(),
        ],
        [
            'start' => Carbon::parse($fecha.' 14:00', $tz)->toRfc3339String(),
            'end' => Carbon::parse($fecha.' 15:00', $tz)->toRfc3339String(),
        ],
    ]);

    $this->calendarMock->shouldReceive('getBusySlots')->once()->andReturn($busySlots);

    $ids = array_column($this->service->horas($fecha), 'id');

    expect($ids)->not->toContain($fecha.'T10:00')
        ->and($ids)->not->toContain($fecha.'T14:00')
        ->and($ids)->toContain($fecha.'T08:00')
        ->and($ids)->toContain($fecha.'T09:00');
});

it('horas() returns all 9 slots for a fully free day', function (): void {
    $fecha = nextWeekday(1);

    $this->calendarMock->shouldReceive('getBusySlots')->once()->andReturn(collect());

    $result = $this->service->horas($fecha);
    $ids = array_column($result, 'id');

    expect($result)->toHaveCount(9);

    foreach ([8, 9, 10, 11, 12, 14, 15, 16, 17] as $hour) {
        expect($ids)->toContain($fecha.'T'.str_pad((string) $hour, 2, '0', STR_PAD_LEFT).':00');
    }
});

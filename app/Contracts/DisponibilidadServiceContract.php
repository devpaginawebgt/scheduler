<?php

namespace App\Contracts;

interface DisponibilidadServiceContract
{
    /**
     * Returns the booking window boundaries and fully-blocked dates.
     *
     * @return array{min_fecha: string, max_fecha: string, fechas_bloqueadas: list<string>}
     */
    public function rango(): array;

    /**
     * Returns available time slots for the given date (YYYY-MM-DD).
     * Returns an empty array when the date is unavailable.
     *
     * @return list<array{id: string, title: string}>
     */
    public function horas(string $fecha): array;
}

<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Models\BotConversation;
use App\Services\CalendarService;
use App\Services\ChatwootApiService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessFlowSubmissionJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $chatwootConvId,
        private readonly string $phone,
        private readonly string $name,
        private readonly array $responseJson,
    ) {}

    public function handle(ChatwootApiService $chatwoot, CalendarService $calendar): void
    {
        $scheduledAt = $this->responseJson['hora'] ?? '';

        $appointment = Appointment::create([
            'chatwoot_conversation_id' => $this->chatwootConvId,
            'phone' => $this->phone,
            'name' => $this->name,
            'company' => $this->responseJson['empresa'] ?? null,
            'email' => $this->responseJson['correo'] ?? null,
            'notes' => $this->responseJson['mensajes'].' mensajes al día' ?? null,
            'scheduled_at' => $scheduledAt,
        ]);

        $eventLink = $this->createCalendarEvent($calendar, $appointment);

        $chatwoot->send($this->chatwootConvId, $this->buildConfirmation((string) $scheduledAt));

        if ($eventLink) {
            $chatwoot->send($this->chatwootConvId, "Hemos enviado la invitación a la reunión a tu correo electrónico, y también te dejamos el link de acceso en este chat:\n{$eventLink}");
        }

        $chatwoot->handoff($this->chatwootConvId, 'Appointment booked via Flow');

        BotConversation::where('chatwoot_conversation_id', $this->chatwootConvId)
            ->update(['state' => 'handoff']);
    }

    private function createCalendarEvent(CalendarService $calendar, Appointment $appointment): ?string
    {
        try {
            $tz = config('whatsapp.flow_timezone', 'America/Guatemala');
            $start = Carbon::parse($appointment->scheduled_at)->shiftTimezone($tz);
            $end = $start->copy()->addHour();

            $attendees = [config('whatsapp.booking_host_email')];
            if ($appointment->email) {
                $attendees[] = $appointment->email;
            }

            $event = $calendar->createEvent([
                'title' => 'Reunión IA Conversacional - '.($appointment->company ?? $appointment->name),
                'start' => $start,
                'end' => $end,
                'description' => $this->buildEventDescription($appointment),
                'location' => null,
                'meet' => true,
                'attendees' => $attendees,
            ]);

            return $event->googleEvent->getHangoutLink() ?? null;
        } catch (\Throwable $e) {
            Log::error('ProcessFlowSubmissionJob: failed to create calendar event', [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function buildEventDescription(Appointment $appointment): string
    {
        $lines = [];

        if ($appointment->company) {
            $lines[] = 'Empresa: '.$appointment->company;
        }

        if ($appointment->email) {
            $lines[] = 'Correo: '.$appointment->email;
        }

        if ($appointment->phone) {
            $lines[] = 'Teléfono: '.$appointment->phone;
        }

        if ($appointment->notes) {
            $lines[] = 'Notas: '.$appointment->notes;
        }

        return implode("\n", $lines);
    }

    private function buildConfirmation(string $scheduledAt): string
    {
        $tz = config('whatsapp.flow_timezone', 'America/Guatemala');
        $dt = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $scheduledAt, $tz);

        if (! $dt) {
            return '¡Listo! Tu cita ha sido registrada. Gracias por agendar con nosotros, te esperamos.';
        }

        $dateFmt = $dt->locale('es')->isoFormat('dddd D [de] MMMM');
        $timeFmt = $this->formatTime($dt);

        return "¡Listo! Te esperamos el {$dateFmt} a las {$timeFmt}. Gracias por agendar tu cita con nosotros.";
    }

    private function formatTime(CarbonImmutable $dt): string
    {
        $hour = (int) $dt->format('g');
        $minutes = $dt->format('i');
        $ampm = $dt->format('A') === 'AM' ? 'a. m.' : 'p. m.';

        return "{$hour}:{$minutes} {$ampm}";
    }
}

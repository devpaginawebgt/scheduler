<?php

namespace App\Services;

use Carbon\Carbon;
use Google_Service_Calendar;
use Google_Service_Calendar_FreeBusyRequest;
use Google_Service_Calendar_FreeBusyRequestItem;
use Illuminate\Support\Collection;
use Spatie\GoogleCalendar\Event;
use Spatie\GoogleCalendar\GoogleCalendarFactory;

class CalendarService
{
    private string $calendarId;

    public function __construct()
    {
        $this->calendarId = config('google-calendar.calendar_id');
    }

    /** @return Collection<int, array{id: string, title: string, start: string, end: string, all_day: bool, description: ?string, location: ?string}> */
    public function getEvents(Carbon $from, Carbon $to): Collection
    {
        return Event::get($from, $to)->map(fn (Event $event) => [
            'id' => $event->id,
            'title' => $event->name,
            'start' => Carbon::parse($event->startDateTime ?? $event->startDate)->toIso8601String(),
            'end' => Carbon::parse($event->endDateTime ?? $event->endDate)->toIso8601String(),
            'all_day' => $event->startDateTime === null,
            'description' => $event->description,
            'location' => $event->location,
        ]);
    }

    /** @return Collection<int, array{start: string, end: string}> */
    public function getBusySlots(Carbon $start, Carbon $end): Collection
    {
        $client = GoogleCalendarFactory::createAuthenticatedGoogleClient(config('google-calendar'));
        $service = new Google_Service_Calendar($client);

        $item = new Google_Service_Calendar_FreeBusyRequestItem;
        $item->setId($this->calendarId);

        $freeBusyRequest = new Google_Service_Calendar_FreeBusyRequest;
        $freeBusyRequest->setTimeMin($start->toRfc3339String());
        $freeBusyRequest->setTimeMax($end->toRfc3339String());
        $freeBusyRequest->setItems([$item]);

        $result = $service->freebusy->query($freeBusyRequest);
        $busyPeriods = $result->getCalendars()[$this->calendarId]->getBusy();

        return collect($busyPeriods)->map(fn ($period) => [
            'start' => $period->getStart(),
            'end' => $period->getEnd(),
        ]);
    }

    /**
     * @param  array{title: string, start: Carbon, end: Carbon, description: ?string, location: ?string, meet: bool, attendees: string[]}  $data
     */
    public function createEvent(array $data): Event
    {
        $event = new Event;
        $event->name = $data['title'];
        $event->startDateTime = $data['start'];
        $event->endDateTime = $data['end'];
        $event->description = $data['description'] ?? null;
        $event->location = $data['location'] ?? null;

        if ($data['meet'] ?? false) {
            $event->addMeetLink();
        }

        foreach ($data['attendees'] ?? [] as $email) {
            $event->addAttendee(['email' => $email]);
        }

        return $event->save(optParams: ['sendUpdates' => 'all']);
    }
}

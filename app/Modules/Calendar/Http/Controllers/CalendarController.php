<?php

namespace App\Modules\Calendar\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Calendar\Http\Requests\StoreCalendarReminderRequest;
use App\Modules\Calendar\Services\CalendarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CalendarController extends Controller
{
    public function __construct(
        private readonly CalendarService $calendarService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $year = (int) $request->query('year', now()->year);
        $month = (int) $request->query('month', now()->month);

        return response()->json(
            $this->calendarService->eventsForMonth($request->user(), $year, $month),
        );
    }

    public function today(Request $request): JsonResponse
    {
        return response()->json(
            $this->calendarService->todayHighlights($request->user()),
        );
    }

    public function storeReminder(StoreCalendarReminderRequest $request): JsonResponse
    {
        return response()->json(
            $this->calendarService->createReminder(
                $request->user(),
                $request->validated(),
            ),
            201,
        );
    }

    public function destroyReminder(Request $request, string $reminderUuid): Response
    {
        $this->calendarService->deleteReminder($request->user(), $reminderUuid);

        return response()->noContent();
    }
}

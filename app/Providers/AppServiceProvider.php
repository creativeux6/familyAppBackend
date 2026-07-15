<?php

namespace App\Providers;

use App\Contracts\Events\EventManagementServiceInterface;
use App\Contracts\FamilyGraph\FamilyGraphRepositoryInterface;
use App\Contracts\Payments\PaymentGatewayInterface;
use App\Listeners\SendConnectionPushNotification;
use App\Listeners\SendFamilyJoinPushNotification;
use App\Listeners\SendMediaSharedPushNotification;
use App\Listeners\SendMessagePushNotification;
use App\Modules\Connections\Events\ConnectionUpdated;
use App\Modules\Events\Services\NullEventManagementService;
use App\Modules\FamilyTree\Events\FamilyMemberJoined;
use App\Modules\Groups\Events\MessageSent;
use App\Modules\Media\Events\MediaSharedWithUser;
use App\Modules\StoragePlans\Services\ManualPlanGateway;
use App\Repositories\FamilyGraph\MysqlFamilyGraphRepository;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Process\Process;

class AppServiceProvider extends ServiceProvider
{
    private static ?Process $scheduleWorker = null;
    public function register(): void
    {
        $this->app->bind(FamilyGraphRepositoryInterface::class, function () {
            return match (config('graph.driver', 'mysql')) {
                'mysql' => $this->app->make(MysqlFamilyGraphRepository::class),
                default => $this->app->make(MysqlFamilyGraphRepository::class),
            };
        });

        $this->app->bind(PaymentGatewayInterface::class, ManualPlanGateway::class);

        $this->app->bind(EventManagementServiceInterface::class, NullEventManagementService::class);
    }

    public function boot(): void
    {
        // Keep legacy path for admin web sockets, but it must use Sanctum so
        // bearer-token mobile clients are authenticated (not session-only 403).
        Broadcast::routes(['middleware' => ['auth:sanctum']]);

        Event::listen(MessageSent::class, SendMessagePushNotification::class);
        Event::listen(FamilyMemberJoined::class, SendFamilyJoinPushNotification::class);
        Event::listen(MediaSharedWithUser::class, SendMediaSharedPushNotification::class);
        Event::listen(ConnectionUpdated::class, SendConnectionPushNotification::class);

        $this->startScheduleWorkerWithServe();
    }

    private function startScheduleWorkerWithServe(): void
    {
        if (! $this->app->runningInConsole() || ! $this->app->environment('local')) {
            return;
        }

        if (($_SERVER['argv'][1] ?? null) !== 'serve') {
            return;
        }

        static::$scheduleWorker = new Process(
            [PHP_BINARY, base_path('artisan'), 'schedule:work'],
            base_path(),
        );
        static::$scheduleWorker->start();

        register_shutdown_function(function (): void {
            $worker = static::$scheduleWorker;
            if ($worker?->isRunning()) {
                $worker->stop(10, defined('SIGTERM') ? SIGTERM : 15);
            }
        });
    }
}

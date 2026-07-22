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
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->configureRateLimiting();

        // Keep legacy path for admin web sockets, but it must use Sanctum so
        // bearer-token mobile clients are authenticated (not session-only 403).
        Broadcast::routes(['middleware' => ['auth:sanctum']]);

        Event::listen(MessageSent::class, SendMessagePushNotification::class);
        Event::listen(FamilyMemberJoined::class, SendFamilyJoinPushNotification::class);
        Event::listen(MediaSharedWithUser::class, SendMediaSharedPushNotification::class);
        Event::listen(ConnectionUpdated::class, SendConnectionPushNotification::class);

        $this->startScheduleWorkerWithServe();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('auth-login', function (Request $request) {
            $ip = $request->ip() ?: 'unknown';
            $phoneKey = $this->phoneFingerprint((string) $request->input('phone', ''));

            return [
                Limit::perMinute((int) config('security.login_per_minute_ip', 10))
                    ->by('login-ip:'.$ip)
                    ->response(fn () => $this->tooManyAttemptsResponse()),
                Limit::perMinute((int) config('security.login_per_minute_phone', 5))
                    ->by('login-phone:'.$phoneKey)
                    ->response(fn () => $this->tooManyAttemptsResponse()),
                Limit::perHour((int) config('security.login_per_hour_ip', 40))
                    ->by('login-ip-hour:'.$ip)
                    ->response(fn () => $this->tooManyAttemptsResponse()),
            ];
        });

        RateLimiter::for('auth-register', function (Request $request) {
            $ip = $request->ip() ?: 'unknown';

            return [
                Limit::perMinute((int) config('security.register_per_minute_ip', 3))
                    ->by('register-ip:'.$ip)
                    ->response(fn () => $this->tooManyAttemptsResponse()),
                Limit::perHour((int) config('security.register_per_hour_ip', 10))
                    ->by('register-ip-hour:'.$ip)
                    ->response(fn () => $this->tooManyAttemptsResponse()),
            ];
        });

        RateLimiter::for('auth-password', function (Request $request) {
            $ip = $request->ip() ?: 'unknown';
            $phoneKey = $this->phoneFingerprint((string) $request->input('phone', ''));

            return [
                Limit::perMinute((int) config('security.password_per_minute_ip', 5))
                    ->by('password-ip:'.$ip)
                    ->response(fn () => $this->tooManyAttemptsResponse()),
                Limit::perHour((int) config('security.password_per_hour_ip', 15))
                    ->by('password-ip-hour:'.$ip)
                    ->response(fn () => $this->tooManyAttemptsResponse()),
                Limit::perHour(8)
                    ->by('password-phone:'.$phoneKey)
                    ->response(fn () => $this->tooManyAttemptsResponse()),
            ];
        });

        RateLimiter::for('api', function (Request $request) {
            $key = $request->user()?->id
                ? 'user:'.$request->user()->id
                : 'ip:'.($request->ip() ?: 'unknown');

            return Limit::perMinute((int) config('security.api_per_minute', 120))
                ->by($key);
        });
    }

    private function phoneFingerprint(string $phone): string
    {
        $normalized = preg_replace('/\s+/', '', $phone) ?: 'empty';

        return hash('sha256', $normalized);
    }

    private function tooManyAttemptsResponse()
    {
        return response()->json([
            'message' => 'Too many attempts. Please wait and try again.',
        ], 429);
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

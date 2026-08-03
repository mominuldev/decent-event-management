<?php

namespace App\Providers;

use App\Domain\Notification\Channels\NotificationChannelResolver;
use App\Domain\Notification\Listeners\QueueManualPaymentVerifiedNotification;
use App\Domain\Notification\Listeners\QueuePaymentFailedNotification;
use App\Domain\Notification\Listeners\QueuePaymentSucceededNotification;
use App\Domain\Notification\Listeners\QueueRefundIssuedNotification;
use App\Domain\Notification\Listeners\QueueRegistrationReceivedNotification;
use App\Domain\Notification\Listeners\QueueTicketDeliveredNotification;
use App\Domain\Payment\Events\ManualPaymentVerified;
use App\Domain\Payment\Events\PaymentFailed;
use App\Domain\Payment\Events\PaymentSucceeded;
use App\Domain\Payment\Events\RefundIssued;
use App\Domain\Payment\Gateways\PaymentGatewayResolver;
use App\Domain\Payment\Models\Payment;
use App\Domain\Registration\Events\RegistrationCreated;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Ticketing\Events\TicketIssued;
use App\Domain\Ticketing\Listeners\IssueTicketForSucceededPayment;
use App\Domain\Ticketing\Models\Ticket;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PaymentGatewayResolver::class);
        $this->app->singleton(NotificationChannelResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Models live under App\Domain\{Module}\Models, not App\Models, so
        // resolve factories by class basename instead of Laravel's default.
        Factory::guessFactoryNamesUsing($this->resolveFactoryName(...));

        // docs/03 §3.14 — `notifications.notifiable_type` stores these short
        // aliases, not fully-qualified class names. Not enforced globally:
        // spatie/laravel-permission's own morph relations (model_has_roles)
        // use full class names for `User`.
        Relation::morphMap([
            'registration' => Registration::class,
            'payment' => Payment::class,
            'ticket' => Ticket::class,
            'attendee' => Attendee::class,
        ]);

        Event::listen(PaymentSucceeded::class, IssueTicketForSucceededPayment::class);

        // Notification outbox writers (docs/01 §1.6) — thin listeners, one
        // per business event, all delegating to Notification\Actions\QueueNotification.
        Event::listen(RegistrationCreated::class, QueueRegistrationReceivedNotification::class);
        Event::listen(PaymentSucceeded::class, QueuePaymentSucceededNotification::class);
        Event::listen(PaymentFailed::class, QueuePaymentFailedNotification::class);
        Event::listen(ManualPaymentVerified::class, QueueManualPaymentVerifiedNotification::class);
        Event::listen(RefundIssued::class, QueueRefundIssuedNotification::class);
        Event::listen(TicketIssued::class, QueueTicketDeliveredNotification::class);

        RateLimiter::for('api', fn ($request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));

        // Generous but bounded — gate scanning can burst well above normal
        // API traffic during a rush at the door.
        RateLimiter::for('scanner', fn ($request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
    }

    /**
     * @param  class-string<Model>  $modelName
     * @return class-string<Factory<Model>>
     */
    private function resolveFactoryName(string $modelName): string
    {
        $factory = 'Database\\Factories\\'.class_basename($modelName).'Factory';

        if (! is_a($factory, Factory::class, true)) {
            throw new \RuntimeException("No factory found for model [{$modelName}]: expected [{$factory}].");
        }

        return $factory;
    }
}

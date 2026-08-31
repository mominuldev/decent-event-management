<?php

namespace App\Providers;

use App\Domain\CheckIn\Services\CheckInDeviceFleetStatus;
use App\Domain\Content\Events\ContentChanged;
use App\Domain\Content\Listeners\RevalidateFrontendContent;
use App\Domain\Notification\Channels\NotificationChannelResolver;
use App\Domain\Notification\Listeners\NotifyEventManagersOfKeyRotation;
use App\Domain\Notification\Listeners\QueueManualPaymentVerifiedNotification;
use App\Domain\Notification\Listeners\QueuePaymentFailedNotification;
use App\Domain\Notification\Listeners\QueuePaymentSucceededNotification;
use App\Domain\Notification\Listeners\QueueRefundIssuedNotification;
use App\Domain\Notification\Listeners\QueueRegistrationReceivedNotification;
use App\Domain\Notification\Listeners\QueueTicketDeliveredNotification;
use App\Domain\Notification\Support\SmsGatewayConfig;
use App\Domain\Payment\Events\ManualPaymentVerified;
use App\Domain\Payment\Events\PaymentFailed;
use App\Domain\Payment\Events\PaymentSucceeded;
use App\Domain\Payment\Events\RefundIssued;
use App\Domain\Payment\Gateways\PaymentGatewayResolver;
use App\Domain\Payment\Models\Payment;
use App\Domain\Registration\Events\RegistrationCreated;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Ticketing\Contracts\ScannerFleetStatus;
use App\Domain\Ticketing\Events\SigningKeyRotated;
use App\Domain\Ticketing\Events\TicketIssued;
use App\Domain\Ticketing\Listeners\GenerateTicketAssets;
use App\Domain\Ticketing\Listeners\IssueTicketForSucceededPayment;
use App\Domain\Ticketing\Models\QrSigningKey;
use App\Domain\Ticketing\Models\Ticket;
use App\Listeners\CheckApplicationHealth;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PaymentGatewayResolver::class);
        $this->app->singleton(NotificationChannelResolver::class);

        // Singleton so one request or queue job reads the `sms` settings
        // group once rather than on every credential lookup; the settings
        // controller flushes it on write, so an edit applies to the next
        // send with no cache TTL in between.
        $this->app->singleton(SmsGatewayConfig::class);

        // Ticketing asks "is the scanner fleet ready for a key rotation?"
        // through an interface it owns; CheckIn answers it. The module
        // boundary (CLAUDE.md, D6) forbids Ticketing querying
        // check_in_devices itself.
        $this->app->bind(ScannerFleetStatus::class, CheckInDeviceFleetStatus::class);
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
            'qr_signing_key' => QrSigningKey::class,
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

        // docs/06 §6.5 — rotating the QR signing key notifies all Event
        // Managers. Ticketing publishes the event and knows nothing about
        // who is told; only this listener does.
        Event::listen(SigningKeyRotated::class, NotifyEventManagersOfKeyRotation::class);

        // First dispatch to the `tickets` Horizon lane (docs/08 Phase 6):
        // renders the QR PNG and bilingual PDF off the issuance transaction.
        Event::listen(TicketIssued::class, GenerateTicketAssets::class);

        // CMS → public site cache invalidation (docs/08 Phase 3.5). Content
        // publishes the event; only this listener knows a Next.js site exists.
        Event::listen(ContentChanged::class, RevalidateFrontendContent::class);

        // Deep dependency checks for the built-in `/up` route (Phase 9,
        // docs/07 §7.3's load-balancer health check and uptime monitoring).
        Event::listen(DiagnosingHealth::class, CheckApplicationHealth::class);

        RateLimiter::for('api', fn ($request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));

        // Generous but bounded — gate scanning can burst well above normal
        // API traffic during a rush at the door.
        RateLimiter::for('scanner', fn ($request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        // Signed private media is asset traffic, not API calls, and one screen
        // fetches many at once — a page of the admin attendee list renders up
        // to 100 avatars. Left on the shared `api` bucket, loading one page
        // would eat most of a staff member's 60/min and starve the SPA's real
        // requests. Signature-authorized and read-only, so a higher ceiling
        // costs nothing but bandwidth; docs/06 §6.7 allows 300/min for admin
        // traffic anyway.
        RateLimiter::for('media', fn ($request) => Limit::perMinute(300)->by($request->user()?->id ?: $request->ip()));

        // Every request on this route spends money — it sends an SMS — so
        // this limiter is a cost control first and an abuse control second
        // (docs/06 §6.7 says exactly that). It was previously on the shared
        // `api` bucket at 60/min, which let one IP spend roughly 2,000 BDT
        // an hour of prepaid SMS balance.
        //
        // Two buckets, because they stop different things: per mobile stops
        // someone burning one victim's balance and filling their inbox; per
        // IP stops a script walking a list of numbers, which the per-mobile
        // limit alone would happily allow.
        RateLimiter::for('sms-code', fn ($request) => [
            Limit::perHour(3)->by('mobile:'.$request->input('mobile')),
            Limit::perHour(20)->by('ip:'.$request->ip()),
        ]);

        // Password attempts. Cheap for us and expensive for a guesser: the
        // per-mobile limit is what bounds an attack on one account, and the
        // per-IP limit bounds spraying one common password across many.
        // Ten, not five, and the gap is deliberate: `verify()` burns a code
        // after five wrong guesses and says so clearly, which is a far
        // better answer to a mistyped digit than a bare 429. The limiter is
        // here to stop a script, not to punish someone squinting at an SMS.
        // Staff password reset. Cheap to send and expensive to be on the
        // receiving end of: without a per-address bucket, one caller can fill
        // a staff member's inbox and train them to ignore the one email that
        // matters. The per-IP bucket is what stops the same script walking a
        // list of addresses to find out who works here — which the identical
        // success response is otherwise carefully hiding.
        RateLimiter::for('staff-password-reset', fn ($request) => [
            Limit::perHour(3)->by('email:'.Str::lower(trim((string) $request->input('email')))),
            Limit::perHour(20)->by('ip:'.$request->ip()),
        ]);

        RateLimiter::for('attendee-login', fn ($request) => [
            Limit::perMinute(10)->by('mobile:'.$request->input('mobile')),
            Limit::perMinute(30)->by('ip:'.$request->ip()),
        ]);
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

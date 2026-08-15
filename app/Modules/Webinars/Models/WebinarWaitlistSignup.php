<?php

namespace App\Modules\Webinars\Models;

use App\Modules\Core\Models\Contact;
use Carbon\CarbonInterface;
use Database\Factories\WebinarWaitlistSignupFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class WebinarWaitlistSignup extends Model
{
    use HasFactory;

    public const NOTIFICATION_MODE_ONCE = 'once';
    public const NOTIFICATION_MODE_RECURRING = 'recurring';

    protected static function newFactory(): WebinarWaitlistSignupFactory
    {
        return WebinarWaitlistSignupFactory::new();
    }

    protected $fillable = [
        'contact_id',
        'webinar_series_id',
        'notified_at',
        'notification_mode',
        'expires_at',
        'ended_at',
        'source_page',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'notified_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function webinarSeries(): BelongsTo
    {
        return $this->belongsTo(WebinarSeries::class);
    }

    public function scopeEligibleForNotification(
        Builder $query,
        ?string $notificationMode = null,
        ?CarbonInterface $at = null,
    ): Builder {
        $notificationMode = $this->normalizedNotificationMode($notificationMode);
        $at ??= now();

        $query
            ->whereNull('ended_at')
            ->where(function (Builder $query) use ($at): void {
                $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $at);
            });

        if ($notificationMode === self::NOTIFICATION_MODE_ONCE) {
            return $query
                ->where('notification_mode', self::NOTIFICATION_MODE_ONCE)
                ->whereNull('notified_at');
        }

        if ($notificationMode === self::NOTIFICATION_MODE_RECURRING) {
            return $query->where(
                'notification_mode',
                self::NOTIFICATION_MODE_RECURRING,
            );
        }

        return $query->where(function (Builder $query): void {
            $query
                ->where('notification_mode', self::NOTIFICATION_MODE_RECURRING)
                ->orWhere(function (Builder $query): void {
                    $query
                        ->where('notification_mode', self::NOTIFICATION_MODE_ONCE)
                        ->whereNull('notified_at');
                });
        });
    }

    public function isRecurringNotificationSubscription(): bool
    {
        return $this->notification_mode === self::NOTIFICATION_MODE_RECURRING;
    }

    private function normalizedNotificationMode(?string $notificationMode): ?string
    {
        if ($notificationMode === null) {
            return null;
        }

        $notificationMode = str_replace(
            '-',
            '_',
            strtolower(trim($notificationMode)),
        );

        if (! in_array($notificationMode, [
            self::NOTIFICATION_MODE_ONCE,
            self::NOTIFICATION_MODE_RECURRING,
        ], true)) {
            throw new InvalidArgumentException(
                "Unsupported Webinar waitlist notification mode [{$notificationMode}].",
            );
        }

        return $notificationMode;
    }
}
<?php

namespace App\Models;

use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory;

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_IN_SERVICE = 'in_service';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_NO_SHOW = 'no_show';

    public const STATUSES = [
        self::STATUS_RESERVED,
        self::STATUS_CONFIRMED,
        self::STATUS_IN_SERVICE,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_NO_SHOW,
    ];

    protected $fillable = [
        'company_id',
        'branch_id',
        'customer_id',
        'professional_id',
        'service_id',
        'starts_at',
        'ends_at',
        'status',
        'notes',
        'cancellation_reason',
        'cancelled_at',
        'no_show_at',
        'deposit_required',
        'deposit_amount',
        'deposit_status',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => 'string',
            'notes' => 'string',
            'cancellation_reason' => 'string',
            'cancelled_at' => 'datetime',
            'no_show_at' => 'datetime',
            'deposit_required' => 'boolean',
            'deposit_amount' => 'decimal:4',
            'deposit_status' => 'string',
        ];
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('starts_at', '>=', now());
    }

    public function scopePast(Builder $query): Builder
    {
        return $query->where('ends_at', '<', now());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_RESERVED,
            self::STATUS_CONFIRMED,
            self::STATUS_IN_SERVICE,
        ]);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    public function scopeNoShow(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_NO_SHOW);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    protected static function booted(): void
    {
        static::saving(function (Appointment $appointment): void {
            $appointment->validateSameCompany();

            if ($appointment->starts_at && $appointment->ends_at && $appointment->starts_at >= $appointment->ends_at) {
                throw ValidationException::withMessages([
                    'ends_at' => 'La fecha de fin debe ser posterior a la fecha de inicio.',
                ]);
            }

            if ($appointment->professional_id && $appointment->branch_id) {
                $professional = Professional::find($appointment->professional_id);
                $isAssigned = $professional
                    ? $professional->branches()->whereKey($appointment->branch_id)->exists()
                    : false;

                if (! $isAssigned) {
                    throw ValidationException::withMessages([
                        'professional_id' => 'El profesional no está asignado a la sucursal indicada.',
                    ]);
                }
            }
        });
    }

    private function validateSameCompany(): void
    {
        $companyId = $this->company_id;

        foreach (['branch_id', 'customer_id', 'professional_id', 'service_id'] as $field) {
            $relatedId = $this->{$field};
            if (! $relatedId) {
                continue;
            }

            $relatedModel = match ($field) {
                'branch_id' => Branch::class,
                'customer_id' => Customer::class,
                'professional_id' => Professional::class,
                'service_id' => Service::class,
                default => null,
            };

            if ($relatedModel && ! $relatedModel::query()->whereKey($relatedId)->where('company_id', $companyId)->exists()) {
                throw ValidationException::withMessages([
                    $field => 'La relación debe pertenecer a la misma empresa de la cita.',
                ]);
            }
        }
    }

    public function isReserved(): bool
    {
        return $this->status === self::STATUS_RESERVED;
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function isInService(): bool
    {
        return $this->status === self::STATUS_IN_SERVICE;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isNoShow(): bool
    {
        return $this->status === self::STATUS_NO_SHOW;
    }

    public function isActive(): bool
    {
        return in_array($this->status, [
            self::STATUS_RESERVED,
            self::STATUS_CONFIRMED,
            self::STATUS_IN_SERVICE,
        ]);
    }

    public function markAsConfirmed(): void
    {
        $this->update(['status' => self::STATUS_CONFIRMED]);
    }

    public function markAsInService(): void
    {
        $this->update(['status' => self::STATUS_IN_SERVICE]);
    }

    public function markAsCompleted(): void
    {
        $this->update(['status' => self::STATUS_COMPLETED]);
    }

    public function markAsCancelled(string $reason = ''): void
    {
        $this->update([
            'status' => self::STATUS_CANCELLED,
            'cancellation_reason' => $reason ?: null,
            'cancelled_at' => now(),
        ]);
    }

    public function markAsNoShow(): void
    {
        $this->update([
            'status' => self::STATUS_NO_SHOW,
            'no_show_at' => now(),
        ]);
    }
}

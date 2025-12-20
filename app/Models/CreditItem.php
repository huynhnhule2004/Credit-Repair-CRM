<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CreditItem extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'client_id',
        'bureau',
        'account_name',
        'account_number',
        'account_type',
        'date_opened',
        'date_last_active',
        'date_reported',
        'balance',
        'high_limit',
        'monthly_pay',
        'past_due',
        'reason',
        'status',
        'dispute_status',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'balance' => 'decimal:2',
        'high_limit' => 'decimal:2',
        'monthly_pay' => 'decimal:2',
        'past_due' => 'decimal:2',
        'date_opened' => 'date',
        'date_last_active' => 'date',
        'date_reported' => 'date',
    ];

    /**
     * Available bureau types.
     */
    public const BUREAU_TRANSUNION = 'transunion';
    public const BUREAU_EXPERIAN = 'experian';
    public const BUREAU_EQUIFAX = 'equifax';

    /**
     * Available dispute statuses.
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELETED = 'deleted';
    public const STATUS_VERIFIED = 'verified';

    /**
     * Get all bureau options.
     *
     * @return array<string, string>
     */
    public static function getBureauOptions(): array
    {
        return [
            self::BUREAU_TRANSUNION => 'TransUnion',
            self::BUREAU_EXPERIAN => 'Experian',
            self::BUREAU_EQUIFAX => 'Equifax',
        ];
    }

    /**
     * Get all dispute status options.
     *
     * @return array<string, string>
     */
    public static function getDisputeStatusOptions(): array
    {
        return [
            self::STATUS_PENDING => 'Pending',
            self::STATUS_SENT => 'Sent',
            self::STATUS_DELETED => 'Deleted',
            self::STATUS_VERIFIED => 'Verified',
        ];
    }

    /**
     * Get the bureau name formatted.
     */
    public function getBureauNameAttribute(): string
    {
        return self::getBureauOptions()[$this->bureau] ?? ucfirst($this->bureau);
    }

    /**
     * Get the dispute status name formatted.
     */
    public function getDisputeStatusNameAttribute(): string
    {
        return self::getDisputeStatusOptions()[$this->dispute_status] ?? ucfirst($this->dispute_status);
    }

    /**
     * Get the client that owns this credit item.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Scope a query to only include items from a specific bureau.
     */
    public function scopeFromBureau($query, string $bureau)
    {
        return $query->where('bureau', $bureau);
    }

    /**
     * Scope a query to only include items with a specific dispute status.
     */
    public function scopeWithDisputeStatus($query, string $status)
    {
        return $query->where('dispute_status', $status);
    }

    /**
     * Scope a query to only include pending items.
     */
    public function scopePending($query)
    {
        return $query->where('dispute_status', self::STATUS_PENDING);
    }
}

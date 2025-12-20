<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'ssn',
        'dob',
        'address',
        'city',
        'state',
        'zip',
        'portal_username',
        'portal_password',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'dob' => 'date',
    ];

    /**
     * Get the client's full name.
     */
    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /**
     * Get the client's full address.
     */
    public function getFullAddressAttribute(): string
    {
        return "{$this->address}, {$this->city}, {$this->state} {$this->zip}";
    }

    /**
     * Get all credit items for this client.
     */
    public function creditItems(): HasMany
    {
        return $this->hasMany(CreditItem::class);
    }

    /**
     * Get pending credit items.
     */
    public function pendingCreditItems(): HasMany
    {
        return $this->hasMany(CreditItem::class)->where('dispute_status', 'pending');
    }

    /**
     * Get credit items by bureau.
     */
    public function creditItemsByBureau(string $bureau): HasMany
    {
        return $this->hasMany(CreditItem::class)->where('bureau', $bureau);
    }

    /**
     * Get credit scores for this client.
     */
    public function creditScores(): HasMany
    {
        return $this->hasMany(CreditScore::class);
    }

    /**
     * Get personal profiles for this client.
     */
    public function personalProfiles(): HasMany
    {
        return $this->hasMany(PersonalProfile::class);
    }

    /**
     * Get personal profile for specific bureau.
     */
    public function personalProfileByBureau(string $bureau): ?PersonalProfile
    {
        return $this->personalProfiles()->where('bureau', $bureau)->first();
    }
}

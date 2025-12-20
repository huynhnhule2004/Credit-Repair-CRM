<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PersonalProfile extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'client_id',
        'bureau',
        'name',
        'date_of_birth',
        'current_address',
        'previous_address',
        'employer',
        'date_reported',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'date_reported' => 'date',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Get bureau name
     */
    public function getBureauNameAttribute(): string
    {
        return match($this->bureau) {
            'transunion' => 'TransUnion',
            'experian' => 'Experian',
            'equifax' => 'Equifax',
            default => ucfirst($this->bureau),
        };
    }
}

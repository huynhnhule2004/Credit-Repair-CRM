<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LetterTemplate extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'content',
        'type',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Available placeholders in templates.
     *
     * @return array<string, string>
     */
    public static function getAvailablePlaceholders(): array
    {
        return [
            '{{client_name}}' => 'Client full name',
            '{{client_first_name}}' => 'Client first name',
            '{{client_last_name}}' => 'Client last name',
            '{{client_address}}' => 'Client full address',
            '{{client_city}}' => 'Client city',
            '{{client_state}}' => 'Client state',
            '{{client_zip}}' => 'Client ZIP code',
            '{{client_phone}}' => 'Client phone',
            '{{client_email}}' => 'Client email',
            '{{client_ssn}}' => 'Client SSN (last 4 digits)',
            '{{client_dob}}' => 'Client date of birth',
            '{{dispute_items}}' => 'List of disputed items',
            '{{current_date}}' => 'Current date',
            '{{bureau_name}}' => 'Credit bureau name',
        ];
    }

    /**
     * Scope a query to only include active templates.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to filter by type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}

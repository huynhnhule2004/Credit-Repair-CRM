<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CreditScore extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'client_id',
        'transunion_score',
        'experian_score',
        'equifax_score',
        'report_date',
        'reference_number',
    ];

    protected $casts = [
        'report_date' => 'date',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Get score for specific bureau
     */
    public function getScoreForBureau(string $bureau): ?int
    {
        return match(strtolower($bureau)) {
            'transunion', 'tu' => $this->transunion_score,
            'experian', 'exp' => $this->experian_score,
            'equifax', 'eq' => $this->equifax_score,
            default => null,
        };
    }
}

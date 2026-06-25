<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoogleIntegration extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'google_email',
        'access_token',
        'refresh_token',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
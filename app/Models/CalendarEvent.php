<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarEvent extends Model
{
    protected $fillable = [
    'workspace_id',
    'user_id',
    'title',
    'description',
    'starts_at',
    'ends_at',
    'color',
    'google_event_id',
    'type',
    'priority',
    'status',
    'meeting_provider',
    'meet_link',
    'attendee_name',
    'attendee_email',
];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
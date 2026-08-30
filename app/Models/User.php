<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * User Model
 *
 * Represents a system user — either an Admin or Adviser.
 * Roles: 'admin' or 'adviser'
 *
 * Admin → can manage all data, view dashboard and reports
 * Adviser   → can encode grades and submit reports for their section
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    // Fields that can be mass-assigned via create() or update()
    // NOTE: 'role' is intentionally NOT here. It's set explicitly in
    // UserController instead (see $user->role = ...), so that even if
    // some future code accidentally mass-assigns from raw request input,
    // a user could never sneak a 'role' field into their own request
    // and self-promote to admin.
    protected $fillable = [
        'name',
        'last_name',
        'first_name',
        'middle_name',
        'email',
        'password',
    ];

    // Fields hidden from JSON output — never expose password
    protected $hidden = [
        'password',
        'remember_token',
    ];

    // =============================================
    // ACCESSORS
    // =============================================

    /**
     * Returns formatted full name: Last Name, First Name Middle Name
     * Accessible as $user->full_name
     */
    public function getFullNameAttribute()
    {
        return $this->last_name . ', ' . $this->first_name . ' ' . ($this->middle_name ?? '');
    }

    // =============================================
    // ROLE HELPERS
    // =============================================

    /** Returns true if user is an adviser */
    public function isAdviser(): bool
    {
        return $this->role === 'adviser';
    }

    /** Returns true if user is an admin */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    // =============================================
    // RELATIONSHIPS
    // =============================================

    /**
     * An adviser belongs to one section
     * Foreign key: sections.adviser_id → users.id
     */
    public function section()
    {
        return $this->hasOne(Section::class, 'adviser_id');
    }
}
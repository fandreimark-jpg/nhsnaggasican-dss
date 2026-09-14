<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * User Model
 *
 * Represents a system user — Admin, Adviser, or Principal.
 * Roles: 'admin', 'adviser', or 'principal'
 *
 * Admin     → manages master/system data (users, tracks, sections, subjects, etc.)
 * Adviser   → encodes grades and submits reports for their assigned section
 * Principal → read-only academic monitoring and Decision Support; may only
 *             write intervention/monitoring decisions, never grades or
 *             assessment records directly
 */
class User extends Authenticatable
{
    use \App\Models\Concerns\ProtectsAcademicHistory;

    use HasFactory, Notifiable;

    // Fields that can be mass-assigned via create() or update()
    // NOTE: 'role' is intentionally NOT here. It's set explicitly in
    // UserController instead (see $user->role = ...), so that even if
    // some future code accidentally mass-assigns from raw request input,
    // a user could never sneak a 'role' field into their own request
    // and self-promote to admin. 'is_active' and 'role_singleton_key' are
    // excluded for the same reason — account status is an Admin-only
    // action (UserController::disable/activate), never end-user input, and
    // role_singleton_key is NEVER set directly by any caller at all (see
    // boot() below).
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

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Matches the DB column default — makes sure a freshly-instantiated
    // `new User()` already has is_active = true in memory BEFORE the first
    // save(), so the boot() saving hook below (which reads $user->is_active
    // to compute role_singleton_key) never sees an unset/null value and
    // wrongly treats a new active admin/principal as inactive.
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Keeps role_singleton_key in sync with (role, is_active) on every
     * save, from every code path — factories, seeders, UserController,
     * tinker — so it can never drift out of sync the way a value that
     * callers set by hand could. Only 'admin' and 'principal' are
     * singleton roles; 'adviser' always computes to null (unlimited
     * advisers, active or not). See the migration for why a UNIQUE index
     * on this nullable column is what makes "only one active Admin /
     * Principal" an actual database constraint instead of just an
     * application-level check.
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function (User $user) {
            $user->role_singleton_key = ($user->is_active && in_array($user->role, ['admin', 'principal'], true))
                ? $user->role
                : null;
        });
    }

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

    /** Returns true if user is a principal */
    public function isPrincipal(): bool
    {
        return $this->role === 'principal';
    }

    /**
     * The named route for this user's role's dashboard — kept here so a
     * new role only needs to be added in one place, not re-derived at
     * every redirect call site (root redirect, /dashboard, etc).
     */
    public function dashboardRouteName(): string
    {
        return match ($this->role) {
            'admin'     => 'admin.dashboard',
            'principal' => 'principal.dashboard',
            default     => 'adviser.dashboard',
        };
    }

    // =============================================
    // ACCOUNT STATUS / ROLE UNIQUENESS
    // =============================================

    /** Roles that must have at most one ACTIVE account at any time. */
    public const SINGLETON_ROLES = ['admin', 'principal'];

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    /** Only 'admin' and 'principal' are capped at one active account — Advisers are unlimited. */
    public static function isSingletonRole(string $role): bool
    {
        return in_array($role, self::SINGLETON_ROLES, true);
    }

    /**
     * Does an ACTIVE account for this role already exist? Used for the
     * friendly, synchronous validation message on the happy path
     * (UserController::store/update/activate) — the actual guarantee
     * against a concurrent duplicate is the role_singleton_key UNIQUE
     * index (see the migration + boot() above), which this check cannot
     * race-proof on its own.
     */
    public static function hasActiveAccountForRole(string $role, ?int $excludeUserId = null): bool
    {
        return static::where('role', $role)
            ->where('is_active', true)
            ->when($excludeUserId, fn($q) => $q->where('id', '!=', $excludeUserId))
            ->exists();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
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
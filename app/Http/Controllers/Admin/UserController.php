<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Section;
use App\Models\Grade;
use App\Helpers\LogActivity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * UserController (Admin)
 *
 * Handles all user account management.
 * Only the Admin can create, update, and delete accounts.
 * Public registration is disabled — all accounts are created here.
 *
 * Role uniqueness (at most one ACTIVE Admin, at most one ACTIVE Principal
 * — Advisers are unlimited): every write here is guarded two ways —
 * 1) an application-level pre-check (roleUniquenessError()) for a fast,
 *    friendly validation message on the normal path, and
 * 2) the role_singleton_key UNIQUE index at the database level (see the
 *    add_active_status_to_users_table migration + User::boot()), which is
 *    the actual guarantee under concurrent requests — two simultaneous
 *    "create Principal" submissions can't both win, because the second
 *    INSERT/UPDATE fails atomically regardless of what the first
 *    request's pre-check saw. store()/update()/activate() all catch that
 *    failure (UniqueConstraintViolationException) and turn it into the
 *    same friendly message rather than a 500.
 */
class UserController extends Controller
{
    private const ROLE_LABELS = ['admin' => 'Admin', 'principal' => 'Principal', 'adviser' => 'Adviser'];

    /**
     * Null if creating/activating $role right now is fine; otherwise the
     * exact rejection message CLAUDE.md specifies for that role.
     */
    private function roleUniquenessError(string $role, ?int $excludeUserId = null): ?string
    {
        if (!User::isSingletonRole($role)) {
            return null; // Adviser — unlimited active accounts.
        }

        if (!User::hasActiveAccountForRole($role, $excludeUserId)) {
            return null;
        }

        return $this->roleConflictMessage($role, forActivation: (bool) $excludeUserId);
    }

    /** Unconditionally formats the rejection message — used directly when a
     *  UniqueConstraintViolationException already proves a conflict exists,
     *  without re-querying to rediscover what's already known. */
    private function roleConflictMessage(string $role, bool $forActivation): string
    {
        $label = self::ROLE_LABELS[$role];

        return "An active {$label} account already exists. Please deactivate the existing {$label} account before "
             . ($forActivation ? "activating this account." : "creating another {$label} account.");
    }

    /**
     * Show all user accounts.
     * Excludes the currently logged-in admin to prevent self-deletion.
     */
    public function index()
    {
        $users = User::where('id', '!=', auth()->id())
            ->with('section')       // eager load section to avoid N+1 queries
            ->orderBy('role')       // admins first, then advisers
            ->orderBy('name')       // alphabetical within each role
            ->paginate(10);

        return view('admin.users', compact('users'));
    }

    /**
     * Store a new user account.
     * Email is auto-generated: username@naggasican.edu.ph
     * Password is hashed using bcrypt via Hash::make()
     */
    public function store(Request $request)
    {
        // Validate all required fields
        $request->validate([
            'last_name'   => 'required|string|max:255',
            'first_name'  => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'username'    => 'required|string|max:255|alpha_dash',
            'password'    => 'required|string|min:8',
            'role'        => 'required|in:adviser,admin,principal',
        ]);

        // Build email from username
        $email = $request->username . '@naggasican.edu.ph';

        // Check if username is already taken
        if (User::where('email', $email)->exists()) {
            return back()->withErrors(['username' => 'This username is already taken.'])->withInput();
        }

        // Fast, friendly pre-check — the actual concurrency-safe guarantee
        // is the role_singleton_key UNIQUE index caught below.
        if ($error = $this->roleUniquenessError($request->role)) {
            return back()->withErrors(['role' => $error])->withInput();
        }

        // Format: Last Name, First Name
        $fullName = $request->last_name . ', ' . $request->first_name;

        try {
            $user = DB::transaction(function () use ($request, $fullName, $email) {
                $user = User::create([
                    'name'        => $fullName,
                    'last_name'   => $request->last_name,
                    'first_name'  => $request->first_name,
                    'middle_name' => $request->middle_name,
                    'email'       => $email,
                    'password'    => Hash::make($request->password), // never store plain text
                ]);

                // 'role' is set explicitly here rather than inside the create()
                // array above — see the note on User::$fillable for why.
                $user->role = $request->role;
                $user->save();

                return $user;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Lost a race against another concurrent "create" request for
            // the same singleton role — the pre-check above passed, but
            // the DB-level guarantee (role_singleton_key) caught it.
            return back()
                ->withErrors(['role' => $this->roleConflictMessage($request->role, forActivation: false)])
                ->withInput();
        }

        // Record action in activity logs
        LogActivity::log(
            'create_user',
            'Created ' . $request->role . ' account: ' . $request->last_name . ', ' . $request->first_name,
            'users',
            null
        );

        return redirect()->route('admin.users')
            ->with('success', ucfirst($request->role) . ' account created successfully!');
    }

    /**
     * Update an existing user account.
     * Password is only updated if a new one is provided.
     */
    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $request->validate([
            'last_name'   => 'required|string|max:255',
            'first_name'  => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'username'    => 'required|string|max:255|alpha_dash',
            'password'    => 'nullable|string|min:8',
            'role'        => 'required|in:adviser,admin,principal',
        ]);

        $email = $request->username . '@naggasican.edu.ph';

        // Check uniqueness — exclude current user from check
        if (User::where('email', $email)->where('id', '!=', $user->id)->exists()) {
            return back()->withErrors(['username' => 'This username is already taken.'])->withInput();
        }

        // Role-change uniqueness (CLAUDE.md section 9): only relevant if
        // this user is CURRENTLY active — an inactive account switching
        // role doesn't create a second active singleton-role account,
        // since its role_singleton_key stays null until it's activated
        // (see User::boot() and the activate() uniqueness check below).
        if ($user->is_active && ($error = $this->roleUniquenessError($request->role, excludeUserId: $user->id))) {
            return back()->withErrors(['role' => $error])->withInput();
        }

        try {
            DB::transaction(function () use ($request, $user, $email) {
                $user->update([
                    'name'        => $request->last_name . ', ' . $request->first_name,
                    'last_name'   => $request->last_name,
                    'first_name'  => $request->first_name,
                    'middle_name' => $request->middle_name,
                    'email'       => $email,
                    // Keep existing password if no new password provided
                    'password'    => $request->password ? Hash::make($request->password) : $user->password,
                ]);

                // 'role' is set explicitly here rather than inside the update()
                // array above — see the note on User::$fillable for why.
                $user->role = $request->role;
                $user->save();
            });
        } catch (UniqueConstraintViolationException $e) {
            return back()
                ->withErrors(['role' => $this->roleConflictMessage($request->role, forActivation: false)])
                ->withInput();
        }

        return redirect()->route('admin.users')
            ->with('success', 'User updated successfully!');
    }

    /**
     * Disable a user account — sets is_active = false. The account is
     * NEVER deleted (grades/assessments/interventions/audit history stay
     * intact, see the destroy() note below for the contrast), and the
     * user simply can no longer log in (see LoginRequest::authenticate()).
     */
    public function disable($id)
    {
        $user = User::findOrFail($id);

        // Prevent an Admin from locking themselves out — same self-protection
        // pattern as destroy()'s self-deletion guard below.
        if ($user->id === auth()->id()) {
            return redirect()->route('admin.users')
                ->with('error', 'You cannot disable your own account.');
        }

        $user->is_active = false;
        $user->save();

        LogActivity::log('disable_user', 'Disabled ' . $user->role . ' account: ' . $user->full_name, 'users', $user->id);

        return redirect()->route('admin.users')
            ->with('success', $user->full_name . ' has been disabled.');
    }

    /**
     * Reactivate a disabled account. Must still obey role-uniqueness —
     * reactivating a second Admin/Principal while one is already active
     * is rejected exactly like creating one would be (CLAUDE.md section 8).
     */
    public function activate($id)
    {
        $user = User::findOrFail($id);

        if ($error = $this->roleUniquenessError($user->role, excludeUserId: $user->id)) {
            return redirect()->route('admin.users')->with('error', $error);
        }

        try {
            $user->is_active = true;
            $user->save();
        } catch (UniqueConstraintViolationException $e) {
            // Lost a race against another concurrent activate/create for
            // the same singleton role.
            return redirect()->route('admin.users')
                ->with('error', $this->roleConflictMessage($user->role, forActivation: true));
        }

        LogActivity::log('activate_user', 'Activated ' . $user->role . ' account: ' . $user->full_name, 'users', $user->id);

        return redirect()->route('admin.users')
            ->with('success', $user->full_name . ' has been activated.');
    }

    /**
     * Delete a user account.
     * Unassigns them from any section before deleting.
     * Cannot delete your own account.
     */
    public function destroy($id)
    {
        $user = User::where('id', $id)->firstOrFail();

        // Prevent self-deletion
        if ($user->id === auth()->id()) {
            return redirect()->route('admin.users')
                ->with('error', 'You cannot delete your own account.');
        }

        // Unassign adviser from their section before deleting
        Section::where('adviser_id', $user->id)->update(['adviser_id' => null]);

        // Set encoded_by to null for grades they encoded
        Grade::where('encoded_by', $user->id)->update(['encoded_by' => null]);

        $user->delete();

        

        return redirect()->route('admin.users')
            ->with('success', 'User removed successfully!');
    }
}
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Section;
use App\Models\Grade;
use App\Helpers\LogActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * UserController (Admin)
 *
 * Handles all user account management.
 * Only the Admin can create, update, and delete accounts.
 * Public registration is disabled — all accounts are created here.
 */
class UserController extends Controller
{
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

        // Format: Last Name, First Name
        $fullName = $request->last_name . ', ' . $request->first_name;

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

        return redirect()->route('admin.users')
            ->with('success', 'User updated successfully!');
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
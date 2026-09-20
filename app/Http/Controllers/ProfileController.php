<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * ProfileController
 * -------------------------------
 * Used by BOTH advisers and admins to update their OWN name,
 * email/username, and password — no need to ask the Admin to
 * edit it for them.
 *
 * IMPORTANT: Role is NOT editable here. That stays Admin-controlled
 * via /admin/users, so no one can casually change their own account type.
 *
 * NOTE: There is no dedicated "profile page" anymore — the form
 * lives in a modal (resources/views/profile/_modal.blade.php)
 * that is available on every screen. That's why these methods
 * redirect back() to whatever page the user was on, instead of
 * to a separate profile route.
 */
class ProfileController extends Controller
{
    /**
     * Save the updated NAME + EMAIL.
     * Runs when the "Save Changes" button is clicked
     * on the first form (Profile Information) in the profile modal.
     *
     * Email is a login credential, so changing it requires the user
     * to confirm their CURRENT password first — same security reason
     * as the password form below: prevents someone at an unattended,
     * still-logged-in session from silently taking over the account
     * by swapping the email to one they control.
     *
     * NOTE: the current_password check only kicks in when the email
     * is ACTUALLY being changed to a different value. If the user is
     * just updating their name, they don't need to re-type it.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Step 1: figure out if the email is actually changing.
        // We compare the submitted value against what's already saved.
        $emailChanged = $request->email !== $user->email;

        // Step 2: build the validation rules. Most fields are always
        // checked, but 'current_password' is only added to the list
        // WHEN the email is changing — that's what makes it "conditional."
        $rules = [
            'last_name'   => 'required|string|max:255',
            'first_name'  => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            // 'email' must stay unique across all users, but we ignore
            // the CURRENT user's own row — otherwise it would always
            // fail the uniqueness check against itself
            'email'       => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ];

        if ($emailChanged) {
            $rules['current_password'] = ['required', 'current_password'];
        }

        // Final pre-demo audit (2026-09-20) — a NAMED error bag. This
        // modal is included on every page, and its "stay open on error"
        // rule used to read the DEFAULT bag: any page's rejected form that
        // happened to use a key named email / first_name / last_name /
        // password (Admin > Users, Admin > Students, My Students) popped the
        // profile modal open on top of the real one, and the modal's
        // $errors->only() call (no such MessageBag method) made that a 500.
        $request->validateWithBag('profile', $rules);

        // Step 3: save to the database
        $user->update([
            'last_name'   => $request->last_name,
            'first_name'  => $request->first_name,
            'middle_name' => $request->middle_name,
            'email'       => $request->email,
            // 'name' is the combined display name — keep it in sync
            // with last/first name whenever either one changes
            'name'        => $request->last_name . ', ' . $request->first_name,
        ]);

        // back() returns the user to whichever page they had the modal
        // open on (dashboard, students, etc.) — there is no dedicated
        // profile page to redirect to anymore.
        return back()->with('success', 'Profile updated successfully!');
    }

    /**
     * Save the NEW PASSWORD.
     * Runs when the "Update Password" button is clicked
     * on the second form (Change Password) in the profile modal.
     *
     * Why we ask for the CURRENT password again:
     * Security measure — if the user left their computer logged in
     * (school lab, shared PC, etc.), someone else can't simply change
     * the password unless they also know the OLD password.
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $request->validateWithBag('profilePassword', [
            // 'current_password' is a built-in Laravel rule — it checks
            // that the value entered matches the user's actual current password
            'current_password' => ['required', 'current_password'],
            // 'confirmed' requires "New Password" and "Confirm New Password"
            // fields on the form to match each other
            'password'         => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        // Hash::make() encrypts the password before saving it —
        // passwords must NEVER be stored as plain text in the database
        $request->user()->update([
            'password' => Hash::make($request->password),
        ]);

        return back()->with('success', 'Password updated successfully!');
    }
}
{{--
    PROFILE MODAL (partial view)
    -----------------------------
    This is included ONCE inside layouts/app.blade.php, so it is
    available on every page (dashboard, students, etc.) — not just
    a dedicated "My Profile" page anymore.

    It opens/closes as a popup, controlled by the openProfileModal()
    and closeProfileModal() JavaScript functions at the bottom.

    If there are validation errors from a previous submit (wrong current
    password, blank name, etc.), the modal automatically stays open so
    the user can see what went wrong — see the class="{{ $errors->any() ... }}" line below.
--}}
<div id="profileModal"
     class="{{ $errors->any() ? 'opacity-100' : 'hidden opacity-0' }} fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div class="modal-box {{ $errors->any() ? 'scale-100 opacity-100' : 'scale-95 opacity-0' }} bg-white rounded-xl shadow-modal w-full max-w-lg p-6 max-h-[90vh] overflow-y-auto transition-all duration-200">

        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-ink">My Profile</h3>
            <button type="button" onclick="closeProfileModal()"
                    aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        {{-- ===================== NAME FORM ===================== --}}
        <div class="mb-6">
            <h4 class="text-sm font-semibold text-gray-700 mb-1">Profile Information</h4>
            <p class="text-xs text-muted mb-4">Update your name and login email. Your account role is managed by the Principal.</p>

            <form method="POST" action="{{ route('profile.update') }}" class="space-y-3">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="form-label">Last Name</label>
                        <input type="text" name="last_name" required
                               value="{{ old('last_name', auth()->user()->last_name) }}"
                               class="form-input">
                    </div>
                    <div>
                        <label class="form-label">First Name</label>
                        <input type="text" name="first_name" required
                               value="{{ old('first_name', auth()->user()->first_name) }}"
                               class="form-input">
                    </div>
                </div>

                <div>
                    <label class="form-label">Middle Name</label>
                    <input type="text" name="middle_name"
                           value="{{ old('middle_name', auth()->user()->middle_name) }}"
                           class="form-input">
                </div>

                <div>
                    <label class="form-label">Username / Email</label>
                    {{-- data-original-email stores the email as it currently is in the
                         database. Every time the user types in this field, our JS
                         (further below) compares the new value against this original
                         to decide whether the Current Password field should appear. --}}
                    <input type="email" name="email" id="profile_email" required
                           data-original-email="{{ auth()->user()->email }}"
                           value="{{ old('email', auth()->user()->email) }}"
                           oninput="toggleCurrentPasswordField()"
                           class="form-input">
                </div>

                {{-- This field starts HIDDEN. It only appears (via JavaScript)
                     when the email above is actually changed to a different value.
                     Editing just the name will never show this. --}}
                <div id="currentPasswordWrapper" class="hidden">
                    <label class="form-label">Current Password</label>
                    <input type="password" name="current_password" id="current_password_field"
                           class="form-input">
                    <p class="text-xs text-muted mt-1">Required to confirm changes to your login email.</p>
                </div>

                @if($errors->has('last_name') || $errors->has('first_name') || $errors->has('middle_name') || $errors->has('email') || $errors->has('current_password'))
                    <div class="alert alert-danger">
                        <ul class="list-disc list-inside">
                            @foreach($errors->only(['last_name','first_name','middle_name','email','current_password']) as $error)
                                <li>{{ is_array($error) ? $error[0] : $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="pt-1">
                    <button type="submit"
                            class="btn btn-primary">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>

        <hr class="my-4">

        {{-- ===================== PASSWORD FORM ===================== --}}
        <div>
            <h4 class="text-sm font-semibold text-gray-700 mb-1">Change Password</h4>
            <p class="text-xs text-muted mb-4">You'll need to enter your current password first.</p>

            <form method="POST" action="{{ route('profile.password.update') }}" class="space-y-3">
                @csrf
                @method('PUT')

                <div>
                    <label class="form-label">Current Password</label>
                    <input type="password" name="current_password" required
                           class="form-input">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="form-label">New Password</label>
                        <input type="password" name="password" required minlength="8"
                               class="form-input">
                    </div>
                    <div>
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="password_confirmation" required minlength="8"
                               class="form-input">
                    </div>
                </div>

                @if($errors->has('current_password') || $errors->has('password'))
                    <div class="alert alert-danger">
                        <ul class="list-disc list-inside">
                            @foreach($errors->only(['current_password','password']) as $error)
                                <li>{{ is_array($error) ? $error[0] : $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="pt-1">
                    <button type="submit"
                            class="btn btn-primary">
                        Update Password
                    </button>
                </div>
            </form>
        </div>

    </div>
</div>

// resources/js/auth.js
// Shows/hides the password on the login page when the eye icon is clicked.
// Keeps the button's accessible name and pressed state in step with what
// the field is actually doing, for screen-reader users.

window.togglePassword = function () {
    const input = document.getElementById("password");
    const icon = document.getElementById("eyeIcon");
    const btn = document.getElementById("togglePasswordBtn");
    const showing = input.type === "password";
    input.type = showing ? "text" : "password";
    icon.className = showing ? "bi bi-eye-slash" : "bi bi-eye";
    if (btn) {
        btn.setAttribute("aria-label", showing ? "Hide password" : "Show password");
        btn.setAttribute("aria-pressed", showing ? "true" : "false");
    }
};

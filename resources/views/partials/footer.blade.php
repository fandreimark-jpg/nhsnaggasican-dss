{{--
    Shared application footer -- included once from layouts/app.blade.php
    so every authenticated page (Admin, Adviser, Principal) gets it
    automatically, instead of each role's views copying their own.
    SYSTEM_FIXES_AND_ML_AUDIT.md, "Add Shared Footer" -- year is computed
    at render time (date('Y')), never a literal, so this never goes stale.
--}}
<footer class="mt-8 pt-4 border-t border-gray-200 text-xs text-gray-400 flex flex-col sm:flex-row items-center justify-between gap-1">
    <p>&copy; {{ date('Y') }} Naggasican National High School. All rights reserved.</p>
    <p>Learner Academic Risk and Decision Support System</p>
</footer>

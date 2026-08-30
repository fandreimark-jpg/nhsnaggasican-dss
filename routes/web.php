<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProfileController;
// Adviser controllers
use App\Http\Controllers\Adviser\DashboardController as AdviserDashboardController;
use App\Http\Controllers\Adviser\StudentController as AdviserStudentController;
use App\Http\Controllers\Adviser\GradeController as AdviserGradeController;
use App\Http\Controllers\Adviser\ReportController as AdviserReportController;
use App\Http\Controllers\Adviser\AssessmentController as AdviserAssessmentController;

// Principal controllers
use App\Http\Controllers\Principal\DashboardController as PrincipalDashboardController;
use App\Http\Controllers\Principal\InterventionController as PrincipalInterventionController;

// Admin controllers
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\TrackController;
use App\Http\Controllers\Admin\SpecializationController;
use App\Http\Controllers\Admin\SubjectController;
use App\Http\Controllers\Admin\SectionController;
use App\Http\Controllers\Admin\StudentController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\AcademicTermController;
use App\Http\Controllers\Admin\ActivityLogController;

// Root redirect
Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route(auth()->user()->dashboardRouteName());
    }
    return redirect()->route('login');
});

Route::get('/dashboard', function () {
    return redirect()->route(auth()->user()->dashboardRouteName());
})->middleware('auth')->name('dashboard');

// =============================================
// PROFILE (self-service — any logged-in user, adviser or admin)
// -----------------------------------------------
// This is where the request goes when "Save Changes" or "Update Password"
// is clicked in the My Profile modal (included on every page — see
// resources/views/profile/_modal.blade.php). Open to BOTH advisers and
// admins (only 'auth' middleware — no role check needed), because
// each user can only ever edit THEIR OWN account. That check happens
// inside the Controller itself, using auth()->id() to know who is logged in.
// =============================================
Route::middleware('auth')->group(function () {
    Route::put('/profile',              [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password',     [ProfileController::class, 'updatePassword'])->name('profile.password.update');
});

// =============================================
// ADVISER ROUTES
// =============================================
Route::middleware(['auth', 'role:adviser'])
    ->prefix('adviser')
    ->name('adviser.')
    ->group(function () {
        Route::get('/dashboard',          [AdviserDashboardController::class, 'index'])->name('dashboard');
        Route::get('/students',           [AdviserStudentController::class, 'index'])->name('students');
        // NEW ROUTE: the "Add Student" modal on the adviser students page
        // submits (POST) here, which runs the store() function in StudentController.
        Route::post('/students',          [AdviserStudentController::class, 'store'])->name('students.store');
        Route::put('/students/{id}',      [AdviserStudentController::class, 'update'])->name('students.update');
        Route::get('/grades',             [AdviserGradeController::class, 'index'])->name('grades');
        Route::post('/grades',            [AdviserGradeController::class, 'store'])->name('grades.store');
        Route::post('/grades/import',  [AdviserGradeController::class, 'importGrades'])->name('grades.import');
        Route::get('/grades/template', [AdviserGradeController::class, 'downloadGradeTemplate'])->name('grades.template');
        Route::post('/grades/verify', [AdviserGradeController::class, 'verifyComputedGrade'])->name('grades.verify');
        Route::get('/submit-report',      [AdviserReportController::class, 'show'])->name('submit.report');
        Route::post('/submit-report',     [AdviserReportController::class, 'submit'])->name('submit.report.post');
        Route::post('/students/import',   [AdviserStudentController::class, 'import'])->name('students.import');
        Route::get('/assessments',          [AdviserAssessmentController::class, 'index'])->name('assessments');
        Route::post('/assessments/detect',  [AdviserAssessmentController::class, 'detect'])->name('assessments.detect');
        Route::post('/assessments/preview', [AdviserAssessmentController::class, 'preview'])->name('assessments.preview');
        Route::post('/assessments/import',  [AdviserAssessmentController::class, 'import'])->name('assessments.import');
    });

// =============================================
// PRINCIPAL ROUTES (read-only academic monitoring / Decision Support)
// =============================================
Route::middleware(['auth', 'role:principal'])
    ->prefix('principal')
    ->name('principal.')
    ->group(function () {
        Route::get('/dashboard', [PrincipalDashboardController::class, 'index'])->name('dashboard');

        // The one WRITE surface the Principal role has — intervention
        // decisions only, never grades or assessments (see RoleAuthorizationTest).
        Route::get('/interventions',            [PrincipalInterventionController::class, 'index'])->name('interventions');
        Route::post('/interventions',           [PrincipalInterventionController::class, 'store'])->name('interventions.store');
        Route::put('/interventions/{intervention}', [PrincipalInterventionController::class, 'update'])->name('interventions.update');
    });

// =============================================
// ADMIN ROUTES
// =============================================
Route::middleware(['auth', 'role:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {

        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        // User Management
        Route::get('/users',            [UserController::class, 'index'])->name('users');
        Route::post('/users',           [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{id}',       [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{id}',    [UserController::class, 'destroy'])->name('users.destroy');

        // Tracks Management
        Route::get('/tracks',           [TrackController::class, 'index'])->name('tracks');
        Route::post('/tracks',          [TrackController::class, 'store'])->name('tracks.store');
        Route::put('/tracks/{id}',      [TrackController::class, 'update'])->name('tracks.update');
        Route::delete('/tracks/{id}',   [TrackController::class, 'destroy'])->name('tracks.destroy');

        // Specializations Management
        Route::get('/specializations',          [SpecializationController::class, 'index'])->name('specializations');
        Route::post('/specializations',         [SpecializationController::class, 'store'])->name('specializations.store');
        Route::put('/specializations/{id}',     [SpecializationController::class, 'update'])->name('specializations.update');
        Route::delete('/specializations/{id}',  [SpecializationController::class, 'destroy'])->name('specializations.destroy');

        // AJAX — Specializations by Track (for dropdowns)
        Route::get('/specializations-by-track/{trackId}', [SpecializationController::class, 'byTrack'])->name('specializations.by.track');

        // Subjects Management
        Route::get('/subjects',         [SubjectController::class, 'index'])->name('subjects');
        Route::post('/subjects',        [SubjectController::class, 'store'])->name('subjects.store');
        Route::put('/subjects/{id}',    [SubjectController::class, 'update'])->name('subjects.update');
        Route::delete('/subjects/{id}', [SubjectController::class, 'destroy'])->name('subjects.destroy');

        // Sections Management
        Route::get('/sections',         [SectionController::class, 'index'])->name('sections');
        Route::post('/sections',        [SectionController::class, 'store'])->name('sections.store');
        Route::put('/sections/{id}',    [SectionController::class, 'update'])->name('sections.update');
        Route::delete('/sections/{id}', [SectionController::class, 'destroy'])->name('sections.destroy');

        //Academic Term
        Route::get('/academic-terms',               [AcademicTermController::class, 'index'])->name('academic-terms');
        Route::post('/academic-terms/{term}/open',  [AcademicTermController::class, 'open'])->whereNumber('term')->name('academic-terms.open');
        Route::post('/academic-terms/{term}/close',[AcademicTermController::class, 'close'])->whereNumber('term')->name('academic-terms.close');
        // Students Management
        // NOTE: no POST /students (add) route here anymore — adding students
        // is now exclusively an Adviser action (see adviser.students.store above),
        // matching the paper's design: "advisers encode, admin monitors."
        Route::get('/students',         [StudentController::class, 'index'])->name('students');
        Route::put('/students/{id}',    [StudentController::class, 'update'])->name('students.update');
        Route::delete('/students/{id}', [StudentController::class, 'destroy'])->name('students.destroy');

        // Reports
        Route::get('/reports', [ReportController::class, 'index'])->name('reports');

        // Activity Logs
        Route::get('/activity-logs', [ActivityLogController::class, 'index'])->name('activity.logs');
    });

require __DIR__.'/auth.php';
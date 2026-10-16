<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProfileController;
// Adviser controllers
use App\Http\Controllers\Adviser\DashboardController as AdviserDashboardController;
use App\Http\Controllers\Adviser\StudentController as AdviserStudentController;
use App\Http\Controllers\Adviser\GradeController as AdviserGradeController;
use App\Http\Controllers\Adviser\ReportController as AdviserReportController;
use App\Http\Controllers\Adviser\AssessmentController as AdviserAssessmentController;
use App\Http\Controllers\Adviser\InterventionController as AdviserInterventionController;
use App\Http\Controllers\Adviser\AdvisoryOverviewController;

// Teaching controllers (Adviser AND Subject Teacher)
use App\Http\Controllers\Teacher\ClassesController as TeacherClassesController;
use App\Http\Controllers\Teacher\ClassRecordController;
use App\Http\Controllers\Teacher\AssessmentFormController;
use App\Http\Controllers\Teacher\LearnerReportController;

// Principal controllers
use App\Http\Controllers\Principal\DashboardController as PrincipalDashboardController;
use App\Http\Controllers\Principal\InterventionController as PrincipalInterventionController;
use App\Http\Controllers\Principal\ReportController as PrincipalReportController;
use App\Http\Controllers\Principal\StudentController as PrincipalStudentController;
use App\Http\Controllers\Principal\SubjectAnalysisController as PrincipalSubjectAnalysisController;
use App\Http\Controllers\Principal\AcademicMonitoringController as PrincipalAcademicMonitoringController;

// Admin controllers
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\TrackController;
use App\Http\Controllers\Admin\SpecializationController;
use App\Http\Controllers\Admin\SubjectController;
use App\Http\Controllers\Admin\SectionController;
use App\Http\Controllers\Admin\SectionSubjectController;
use App\Http\Controllers\Admin\StudentController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\AcademicTermController;
use App\Http\Controllers\Admin\AcademicYearController;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\TeachingAssignmentController;

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
        // Advisers VIEW students in their assigned section only. Adding,
        // importing AND editing learner master data is exclusively an Admin
        // action (admin.students.*); the adviser edit route was removed
        // 2026-09-27 — learner records are Admin-owned master data.
        Route::get('/students',           [AdviserStudentController::class, 'index'])->name('students');
        Route::get('/grades',             [AdviserGradeController::class, 'index'])->name('grades');
        Route::post('/grades',            [AdviserGradeController::class, 'store'])->name('grades.store');
        Route::post('/grades/import',  [AdviserGradeController::class, 'importGrades'])->name('grades.import');
        Route::get('/grades/template', [AdviserGradeController::class, 'downloadGradeTemplate'])->name('grades.template');
        // Verify Grades is its own page ("Encode / Verify split", 2026-09-28);
        // /grades stays Encode Grades. The POST verify endpoints are unchanged.
        Route::get('/verify-grades',  [AdviserGradeController::class, 'verification'])->name('verify-grades');
        Route::post('/grades/verify', [AdviserGradeController::class, 'verifyComputedGrade'])->name('grades.verify');
        Route::get('/grades/verify-all/preview', [AdviserGradeController::class, 'verifyAllRemainingPreview'])->name('grades.verify-all.preview');
        Route::post('/grades/verify-all', [AdviserGradeController::class, 'verifyAllRemaining'])->name('grades.verify-all');
        Route::get('/submit-report',      [AdviserReportController::class, 'show'])->name('submit.report');
        Route::post('/submit-report',     [AdviserReportController::class, 'submit'])->name('submit.report.post');
        // RETIRED page ("Class Record consolidation", 2026-09-28): an old
        // bookmark/link only REDIRECTS to the matching Class Record, with the
        // selection re-validated. Verification moved to adviser.grades.
        Route::get('/assessments',          [AdviserAssessmentController::class, 'index'])->name('assessments');
        // "Subject Teacher and Class Record" pass — the advisory overview:
        // every subject's results and concerns for the adviser's section.
        Route::get('/advisory-overview',    [AdvisoryOverviewController::class, 'index'])->name('advisory-overview');
        // Read + acknowledge only — the adviser cannot create, approve, or
        // delete an intervention (see Adviser\InterventionController's
        // docblock and RoleAuthorizationTest).
        Route::get('/interventions', [AdviserInterventionController::class, 'index'])->name('interventions');
        // Registered BEFORE the {intervention}-scoped route below so
        // "acknowledge-all" is never captured as a route-model-bound id.
        Route::post('/interventions/acknowledge-all', [AdviserInterventionController::class, 'acknowledgeAll'])->name('interventions.acknowledge-all');
        // Registered BEFORE the {intervention}-scoped route below for the
        // same reason as acknowledge-all above — "deliver-group" must
        // never be captured as a route-model-bound id. The ONE exception
        // to "delivery is never bulk" — see markDeliveredGroup()'s
        // docblock: several learners genuinely covered by the SAME
        // activity, one required note, honestly labelled as a group act.
        Route::post('/interventions/deliver-group', [AdviserInterventionController::class, 'markDeliveredGroup'])->name('interventions.deliver-group');
        Route::post('/interventions/{intervention}/acknowledge', [AdviserInterventionController::class, 'acknowledge'])->name('interventions.acknowledge');
        Route::post('/interventions/{intervention}/deliver', [AdviserInterventionController::class, 'markDelivered'])->name('interventions.deliver');
        // The intervention's own page (schedule, completion, requests, history).
        Route::get('/interventions/{intervention}', [\App\Http\Controllers\Teacher\InterventionController::class, 'show'])->whereNumber('intervention')->name('interventions.show');
        // The section adviser (the responsible staff) manages the schedule
        // directly (2026-10-07); every check is in InterventionScheduleService.
        Route::post('/interventions/{intervention}/complete', [\App\Http\Controllers\Teacher\InterventionController::class, 'complete'])->name('interventions.complete');
        Route::post('/interventions/{intervention}/requests', [\App\Http\Controllers\Teacher\InterventionController::class, 'request'])->name('interventions.requests');
        Route::post('/interventions/{intervention}/schedule', [\App\Http\Controllers\Teacher\InterventionController::class, 'schedule'])->name('interventions.schedule');
        Route::post('/interventions/{intervention}/extend', [\App\Http\Controllers\Teacher\InterventionController::class, 'extend'])->name('interventions.extend');
        Route::post('/interventions/{intervention}/reopen', [\App\Http\Controllers\Teacher\InterventionController::class, 'reopen'])->name('interventions.reopen');
    });

// "Class Record refinements" pass (2026-09-28): the teacher/adviser
// assessment FILE UPLOAD (adviser.assessments.detect/preview/import), the
// DSS Assessment Record download that fed it (adviser.assessments.record)
// and the score-carrying item endpoints (adviser.assessments.item.*) are
// RETIRED — the routes no longer exist, so neither a direct request nor a
// previously staged import can reach them. Assessment definitions are
// created/edited on teacher.assessments.*; scores are entered only in the
// Class Record grid (teacher.class-record.save).

// =============================================
// TEACHING WORKSPACE — Adviser AND Subject Teacher
// -----------------------------------------------
// My Classes, the Class Record (score entry), the full-page Add/Edit
// Assessment forms, exports and the learner report. Scope is decided per
// request by TeachingAccess: advisory sections are readable in full;
// assigned subject/section/term combinations are readable AND editable.
// =============================================
Route::middleware(['auth', 'role:adviser,subject_teacher'])
    ->prefix('teacher')
    ->name('teacher.')
    ->group(function () {
        Route::get('/classes',                   [TeacherClassesController::class, 'index'])->name('classes');
        Route::get('/class-record',              [ClassRecordController::class, 'show'])->name('class-record');
        Route::post('/class-record/save',        [ClassRecordController::class, 'save'])->name('class-record.save');
        Route::post('/class-record/preview',     [ClassRecordController::class, 'preview'])->name('class-record.preview');
        Route::get('/class-record/export',       [ClassRecordController::class, 'export'])->name('class-record.export');
        Route::get('/class-record/copy',         [ClassRecordController::class, 'copy'])->name('class-record.copy');
        Route::get('/assessments/create',        [AssessmentFormController::class, 'create'])->name('assessments.create');
        Route::post('/assessments',              [AssessmentFormController::class, 'store'])->name('assessments.store');
        Route::get('/assessments/{assessment}/edit', [AssessmentFormController::class, 'edit'])->name('assessments.edit');
        Route::put('/assessments/{assessment}',  [AssessmentFormController::class, 'update'])->name('assessments.update');
        Route::get('/assessments/{assessment}/history', [AssessmentFormController::class, 'history'])->name('assessments.history');
        Route::get('/learners/{student}/report', [LearnerReportController::class, 'show'])->name('learners.report');
    });

// Old /teacher/interventions bookmarks (2026-10-07): ADVISERS are redirected to
// their own Interventions; subject teachers have no intervention access at all
// (role middleware: 403). The former teacher POST endpoints no longer exist.
Route::middleware(['auth', 'role:adviser'])
    ->prefix('teacher')
    ->name('teacher.')
    ->group(function () {
        Route::get('/interventions', [\App\Http\Controllers\Teacher\InterventionController::class, 'index'])->name('interventions');
        Route::get('/interventions/{intervention}', [\App\Http\Controllers\Teacher\InterventionController::class, 'show'])->whereNumber('intervention')->name('interventions.show');
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
        Route::post('/interventions/bulk',      [PrincipalInterventionController::class, 'storeBulk'])->name('interventions.bulk-store');
        Route::put('/interventions/{intervention}', [PrincipalInterventionController::class, 'update'])->name('interventions.update');
        Route::post('/interventions/approve-pending', [PrincipalInterventionController::class, 'approvePending'])->name('interventions.approve-pending');
        // Scheduling, deadlines, completion review and expiry (2026-10-06).
        Route::get('/interventions/{intervention}', [\App\Http\Controllers\Principal\InterventionScheduleController::class, 'show'])->whereNumber('intervention')->name('interventions.show');
        Route::post('/interventions/{intervention}/responsible', [\App\Http\Controllers\Principal\InterventionScheduleController::class, 'responsible'])->name('interventions.responsible');
        // RETIRED 2026-10-07 (the responsible staff set/extend/reopen schedules):
        // kept only to refuse old forms clearly; nothing is written.
        Route::post('/interventions/{intervention}/schedule', [\App\Http\Controllers\Principal\InterventionScheduleController::class, 'retired'])->name('interventions.schedule');
        Route::post('/interventions/{intervention}/reopen', [\App\Http\Controllers\Principal\InterventionScheduleController::class, 'retired'])->name('interventions.reopen');
        Route::post('/interventions/{intervention}/cancel', [\App\Http\Controllers\Principal\InterventionScheduleController::class, 'cancel'])->name('interventions.cancel');
        Route::post('/intervention-requests/{interventionRequest}/decide', [\App\Http\Controllers\Principal\InterventionScheduleController::class, 'decide'])->name('intervention-requests.decide');
        Route::get('/reports', [PrincipalReportController::class, 'index'])->name('reports');
        Route::get('/students', [PrincipalStudentController::class, 'index'])->name('students');
        Route::get('/students/{student}', [PrincipalStudentController::class, 'show'])->name('students.show');
        Route::get('/subject-analysis', [PrincipalSubjectAnalysisController::class, 'index'])->name('subject-analysis');
        // Phase 2D — read-only: academic concerns For Review and their history.
        Route::get('/academic-monitoring', [PrincipalAcademicMonitoringController::class, 'index'])->name('academic-monitoring');
        // Phase 2E — records that the Principal inspected an open concern's
        // evidence; changes nothing else and creates no intervention.
        Route::post('/academic-monitoring/{concern}/review', [PrincipalAcademicMonitoringController::class, 'review'])->name('academic-monitoring.review');
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
        Route::post('/users/{id}/disable', [UserController::class, 'disable'])->name('users.disable');
        Route::post('/users/{id}/activate', [UserController::class, 'activate'])->name('users.activate');
        Route::delete('/users/{id}',    [UserController::class, 'destroy'])->name('users.destroy');

        // Master data — Tracks, Specializations, Subjects, Sections — is
        // managed by hand (Add / Edit). The bulk spreadsheet imports that
        // used to sit beside them were removed ("Subject applicability"
        // refactor, 2026-09-20): no official or client-provided file format
        // exists for any of them, and this project does not invent one
        // (CLAUDE.md, "No custom Subject Offerings spreadsheet"). The
        // learner roster (students.import / import-from-ecr) and the
        // prescribed E-Class Record are the only uploads that remain.

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
        // Section subjects — the RESOLVED list a section takes per term
        // (SubjectApplicabilityService, from Admin > Subjects' configuration),
        // plus the one per-section decision left: choosing an elective for
        // a section the curriculum cannot match one to. Nothing here is
        // written by an upload; the prescribed ECR only validates against it.
        Route::get('/sections/{section}/subjects',                 [SectionSubjectController::class, 'index'])->name('sections.subjects');
        Route::post('/sections/{section}/subjects',                [SectionSubjectController::class, 'store'])->name('sections.subjects.store');
        Route::delete('/sections/{section}/subjects/{subject}',    [SectionSubjectController::class, 'destroy'])->name('sections.subjects.destroy');

        //Academic Term
        Route::get('/academic-terms',               [AcademicTermController::class, 'index'])->name('academic-terms');
        Route::post('/academic-terms/{term}/open',  [AcademicTermController::class, 'open'])->whereNumber('term')->name('academic-terms.open');
        Route::post('/academic-terms/{term}/close',[AcademicTermController::class, 'close'])->whereNumber('term')->name('academic-terms.close');
        // Academic term editing — {academicTerm} route-model-binds
        // by the row's own id (not the term NUMBER open()/close() use above),
        // since a term row's identity for editing purposes is the
        // specific (school_year, term) record, not "whichever term N is for
        // the currently active school year."
        Route::put('/academic-terms/{academicTerm}',    [AcademicTermController::class, 'update'])->name('academic-terms.update');
        // SYSTEM_FIXES_AND_ML_AUDIT.md, "Remove Hardcoded Academic Year" —
        // explicit academic-year configuration, separate from term
        // open/close control above.
        Route::post('/academic-years',              [AcademicYearController::class, 'store'])->name('academic-years.store');
        Route::post('/academic-years/{academicYear}/activate', [AcademicYearController::class, 'activate'])->name('academic-years.activate');
        Route::put('/academic-years/{academicYear}',    [AcademicYearController::class, 'update'])->name('academic-years.update');
        // Students Management — adding and importing students is exclusively
        // an Admin action (master-data ownership); advisers may only view
        // their own section's students and edit existing records.
        Route::get('/students',         [StudentController::class, 'index'])->name('students');
        Route::post('/students',        [StudentController::class, 'store'])->name('students.store');
        Route::post('/students/import', [StudentController::class, 'import'])->name('students.import');
        Route::put('/students/{id}',    [StudentController::class, 'update'])->name('students.update');
        Route::delete('/students/{id}', [StudentController::class, 'destroy'])->name('students.destroy');
        // "Multi-school-year academic history" work order, PART 15 — enroll /
        // promote one learner into a section of another school year. Creates
        // a NEW student_enrollments row; never rewrites an old one, never
        // creates a second Student. Admin only, one learner per request.
        Route::post('/students/{student}/enroll', [StudentController::class, 'enroll'])->name('students.enroll');
        // "Draft roster from an E-Class Record" feature — export only, never
        // creates a student; the admin reviews/corrects the CSV and imports
        // it through students.import above, unchanged.
        Route::post('/students/extract-roster',          [StudentController::class, 'extractRosterPreview'])->name('students.extract-roster');
        Route::get('/students/extract-roster/download',  [StudentController::class, 'downloadRosterExtraction'])->name('students.extract-roster.download');
        // Rejected-rows re-upload feature — students.import above stores just
        // the rejected rows in session; this streams them back out as a CSV
        // in the same upload shape.
        Route::get('/students/rejected/download', [StudentController::class, 'downloadRejectedStudents'])->name('students.rejected.download');
        // "Import Learners from ECR" — a DISTINCT feature from
        // students.import (plain CSV/XLSX) and students.extract-roster
        // (export-only draft): reads a real SSHS or Grade 12 class-record
        // workbook and writes to `students` directly, but ONLY learner
        // identity, and only after the Admin reviews a classified preview
        // (Insert/Existing/Conflict/Rejected) and explicitly confirms it.
        Route::post('/students/import-from-ecr/preview', [StudentController::class, 'importFromEcrPreview'])->name('students.import-from-ecr.preview');
        Route::post('/students/import-from-ecr/confirm', [StudentController::class, 'importFromEcrConfirm'])->name('students.import-from-ecr.confirm');

        // Teaching assignments — who teaches which subject in which section,
        // per term ("Subject Teacher and Class Record" pass). The only thing
        // that grants the right to encode assessment evidence.
        Route::get('/teaching-assignments',                  [TeachingAssignmentController::class, 'index'])->name('teaching-assignments');
        Route::post('/teaching-assignments',                 [TeachingAssignmentController::class, 'store'])->name('teaching-assignments.store');
        Route::get('/teaching-assignments/{assignment}/edit', [TeachingAssignmentController::class, 'edit'])->name('teaching-assignments.edit');
        Route::put('/teaching-assignments/{assignment}',     [TeachingAssignmentController::class, 'update'])->name('teaching-assignments.update');
        Route::delete('/teaching-assignments/{assignment}',  [TeachingAssignmentController::class, 'destroy'])->name('teaching-assignments.destroy');

        // Reports
        Route::get('/reports', [ReportController::class, 'index'])->name('reports');

        // Activity Logs
        Route::get('/activity-logs', [ActivityLogController::class, 'index'])->name('activity.logs');
    });

require __DIR__.'/auth.php';

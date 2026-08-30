# SHS DECISION SUPPORT SYSTEM
# MASTER ENGINEERING INSTRUCTIONS

## PROJECT ROLE

This is an EXISTING Senior High School Decision Support
System built with Laravel.

Do not rebuild the application from scratch.

The system must be developed incrementally while preserving
existing functionality and existing data.

---

# THREE SYSTEM ROLES

The system has three primary roles:

1. ADMIN
2. ADVISER
3. PRINCIPAL

## ADMIN

Admin is responsible for system/master data management.

Potential areas:

- Users
- Tracks
- Specializations
- Subjects
- Sections
- Students
- Academic Terms
- School Year
- Other system configuration

## ADVISER

Adviser is responsible for assigned academic data.

Main workflow:

Select Academic Term
→ Select Section
→ Select Subject
→ Upload Assessment Form
→ Detect Assessment Columns
→ Verify Classification
→ Validate Data
→ Preview
→ Import
→ Analyze Performance
→ Prepare Final Grade
→ Term Readiness
→ Submit

Advisers must only access students, sections, subjects,
and academic data that they are authorized to manage.

## PRINCIPAL

Principal is the primary Decision Support user.

Principal areas include:

- Dashboard
- Student Performance
- Assessment Analysis
- Subject Analysis
- Section Analysis
- At-Risk Students
- Students Needing Monitoring
- Decision Support
- Intervention
- Progress Monitoring
- Reports

The DSS provides recommendations and evidence.

The Principal makes the final academic decision.

---

# GRADING CONFIGURATION

Current grading configuration:

Written Work = 25%

Performance Task = 50%

Examination = 25%

Total = 100%.

Do not simply average the three component percentages.

Component percentage:

earned score / maximum score × 100

Weighted component:

component percentage × component weight

Final Grade:

Written Work contribution
+
Performance Task contribution
+
Examination contribution

Example test:

Written Work = 84.44%
Performance Task = 85%
Examination = 70%

Expected:

WW = 21.11
PT = 42.50
Exam = 17.50

Final = 81.11

This must be tested through automated tests.

Do not hard-code the example into production logic.

---

# ASSESSMENT SYSTEM

Assessment forms may contain:

- Quiz
- Activity
- Performance Task
- Examination
- Other assessment records

The system should detect likely assessment categories.

Examples:

Quiz 1
Quiz 2
Activity 1

→ Written Work

Performance Task 1
Project
Presentation

→ Performance Task

Exam
Final Exam

→ Examination

The detection must be shown to the Adviser for verification.

Ambiguous columns must not be silently classified.

The Adviser must be able to correct the classification before
import.

---

# ASSESSMENT VALIDATION

Validate:

- Student existence
- Student-section relationship
- Adviser authorization
- Subject
- Academic term
- School year
- Assessment type
- Score
- Maximum score
- Duplicate records
- Missing records
- Invalid values

Invalid data must not silently enter the database.

---

# DECISION SUPPORT

The DSS must not depend only on final grade.

It must analyze the underlying assessment components.

Analyze:

- Written Work
- Performance Task
- Examination
- Individual assessments when available
- Subject performance
- Component gaps
- Trends
- Completion
- Risk indicators
- Intervention status

Example:

Written Work = 84%
Performance Task = 60%
Examination = 70%

Target = 75%.

The system should identify:

Written Work:
84%
Gap = +9
Status = On Track

Performance Task:
60%
Gap = -15
Status = Needs Attention

Examination:
70%
Gap = -5
Status = Needs Attention

Primary concern:

Performance Task.

The DSS should explain the reason.

---

# PRINCIPAL DECISION SUPPORT

The Principal dashboard should provide useful information such
as:

- Total Students
- On Track
- Needs Monitoring
- At Risk
- Under Intervention
- Weakest Subjects
- Weakest Components
- Assessment Completion
- Performance Trends
- Intervention Status

Filters may include:

- School Year
- Academic Term
- Grade Level
- Track
- Specialization
- Section
- Subject
- Risk Level
- Assessment Component

---

# INTERVENTION

The DSS recommends.

The Principal decides.

Do not automatically approve interventions.

Possible recommendations:

- Remediation
- Additional Learning Activity
- Additional Performance Task
- Teacher Monitoring
- Attendance Monitoring
- Parent/Guardian Conference
- Other appropriate intervention

Principal decisions must be recorded where appropriate.

---

# PROGRESS MONITORING

The system should support before/after comparison.

Example:

Before Intervention:
Performance Task = 60%

After Intervention:
Performance Task = 78%

Change:
+18 percentage points

Use neutral language.

Do not claim an intervention caused improvement unless the
available evidence supports that conclusion.

---

# TERM READINESS

Before final term submission, verify:

- Assessment forms uploaded
- Assessment mappings verified
- Required records present
- Invalid records resolved
- Students accounted for
- Final grade calculation available
- No unresolved blocking errors

Example:

45 students
43 complete
2 incomplete

Status:

NOT READY

---

# DATABASE SAFETY

This is an existing system.

Protect existing data.

NEVER use:

php artisan migrate:fresh

php artisan db:wipe

DROP DATABASE

unless explicitly authorized.

Before modifying the database:

1. Inspect existing migrations.
2. Inspect current schema.
3. Identify reusable tables.
4. Identify relationships.
5. Create the smallest safe migration.
6. Run the migration.
7. Verify migration status.
8. Test affected functionality.

Do not create duplicate tables when existing tables can be
extended safely.

---

# AUTHORIZATION

Do not rely only on hiding UI menu items.

Use proper authorization through appropriate:

- Middleware
- Policies
- Gates
- Query scoping

Test direct URL access.

Test unauthorized access.

Examples:

Adviser must not access another Adviser's students.

Adviser must not access Admin functions.

Admin must not access unauthorized Principal DSS functions.

Principal must be able to access authorized DSS information.

---

# LOOP ENGINEERING

EVERY FEATURE MUST FOLLOW:

INSPECT
↓
PLAN
↓
IMPLEMENT
↓
RUN
↓
TEST
↓
DIAGNOSE
↓
FIX
↓
RETEST
↓
REGRESSION CHECK
↓
VERIFY
↓
DOCUMENT

Never stop immediately after generating code.

---

# MANDATORY EXECUTION RULE

DO NOT STOP AFTER GENERATING CODE.

After modifying code:

1. Run required commands.
2. Run relevant tests.
3. Verify migrations.
4. Verify seeders when applicable.
5. Verify database structure.
6. Verify relationships.
7. Verify routes.
8. Verify authorization.
9. Verify validation.
10. Verify affected workflows.
11. Check runtime errors.
12. Check frontend/build errors when applicable.
13. Check regressions.

If an error occurs:

READ ERROR
↓
IDENTIFY ROOT CAUSE
↓
INSPECT RELATED CODE
↓
APPLY SAFE FIX
↓
RUN COMMAND AGAIN
↓
RUN TEST AGAIN
↓
CHECK REGRESSIONS
↓
VERIFY AGAIN

Do not merely report errors.

Fix them whenever possible.

---

# NO FALSE COMPLETION

Never claim:

"Done"

"Completed"

"Successfully implemented"

unless the implementation has actually been verified.

If something remains broken:

STATUS: INCOMPLETE

Explain the exact problem and continue fixing it if possible.

---

# TESTING

Create or update automated tests where appropriate.

Test:

- Grading calculations
- Assessment classification
- Assessment validation
- Assessment import
- Authorization
- DSS calculations
- Risk classification
- Term readiness
- Intervention workflow

Test both:

- successful cases
- failure/invalid cases

---

# REGRESSION

After every feature:

Check that existing:

- authentication
- roles
- dashboards
- students
- subjects
- sections
- academic terms
- grading
- reports
- existing DSS

still work.

Do not sacrifice existing functionality to implement a new
feature.

---

# DEFINITION OF DONE

A feature is complete only when:

[ ] Requirement implemented
[ ] Existing functionality preserved
[ ] Database verified
[ ] Migration verified
[ ] Seeders verified when applicable
[ ] Models verified
[ ] Relationships verified
[ ] Routes verified
[ ] Authorization verified
[ ] Validation verified
[ ] Tests pass
[ ] Frontend verified
[ ] Workflow verified
[ ] Error cases checked
[ ] Regression checked
[ ] No known blocking errors remain
[ ] Documentation updated
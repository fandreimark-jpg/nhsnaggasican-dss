# Vercel container deployment

Prepared 2026-09-14. Use synthetic/anonymized demo data only. No deployment,
database migration, commit, push, or production-model training was performed.

## Audit and original error

The HTTP application is Laravel 12/PHP, entered through `public/index.php`.
`artisan` is its CLI. Python is a subprocess, not a web server. The reported
error means Vercel selected its Python builder instead of the container builder.
This checkout had no root `pyproject.toml`, root `requirements.txt`, or
`vercel.json`; its existing `Dockerfile.vercel` was empty and untracked. The
remote project's preset/root directory/build logs were not accessible, so the
specific remote setting that caused Python detection cannot be established.

Reviewed composer/package manifests and locks, all config files, bootstrap,
entrypoints, analytics modules/tests/requirements, ReportController, Git ignores,
and all application filesystem writes. No hard-coded Windows executable needs
changing: `config('services.python_path')` already reads `PYTHON_PATH` (default
`python` for Windows). ReportController uses Symfony Process with an argument
array: interpreter, absolute `analytics/classify.py`, input JSON, output JSON.
It allows 120 seconds and removes both UUID-named storage/app JSON files in
`finally`. Risk results are persisted to MySQL. Existing risk overrides remain.

## Runtime and builds

`Dockerfile.vercel` uses Debian Bookworm, official PHP 8.2 Apache, and official
Python 3.14.6. PHP receives MySQL PDO, mbstring, GD, ZIP, intl, bcmath, OPcache,
cURL and XML extensions; other required core extensions come with the PHP image.
Composer installs locked production dependencies and checks platform requirements.
Python/pip and its installed libraries are copied from the Python stage into
the same Debian-based PHP runtime. NumPy 2.4.6, scikit-learn 1.9.0, and joblib
1.5.3 retain the existing requirements pins; pip resolves their transitive
dependencies (including SciPy/threadpoolctl). No model is trained during build.

Node 22 runs `npm ci` and `npm run build` using the package lock. Composer's
vendor tree is available to Tailwind's Laravel pagination template scan.
Only `public/build` is copied from the asset stage into the runtime. Node and
node_modules are not shipped. PHP's Apache document root is strictly `public/`;
mod_rewrite sends application routes through Laravel. Apache runs in foreground
and listens on `PORT` (8080 default, overridable). Its workers run as www-data;
the parent initializes Apache as root. storage and bootstrap/cache are writable
by www-data, while source and model files are not worker-writable.

Laravel logs go to stderr and Apache logs to stdout/stderr. No `.env`, local
database, student upload, backup, cached configuration, session, compiled view,
or development vendor/node_modules is copied into the build context. The
public curriculum CSV needed by seeders is retained; spreadsheets and datasets
are excluded. Runtime configuration comes from Vercel environment variables.
Configuration is deliberately uncached, so secrets never enter image layers.
Do not run config:cache during the image build; optional runtime caching must
occur only after runtime variables are available. No startup migrations occur.

## Required existing model

`analytics/classify.py:load_model()` (renamed from `get_model()` by the
2026-09-19 ML architecture pass) loads the model registry's ACTIVE model if
one has been promoted, and otherwise `analytics/model_cache.pkl`. It
explicitly refuses automatic training and raises a controlled error if
neither exists, so the image MUST still ship `model_cache.pkl`. The existing
local artifact is a 200-tree, one-feature RandomForestClassifier — the legacy
synthetic prototype, see `analytics/README.md`. Its SHA-256 is:

`14b01918f3b210777bf3013d1f56bba79062b20f3b13e2fd05ff8314027807cd`

It remains byte-for-byte unchanged. The ignore rule was removed so this approved
synthetic model can accompany a Git deployment; it is currently **untracked**
until you explicitly add it. `.gitattributes` marks pickle files binary. The
image build fails if the file is absent or cannot load/predict with the pinned
libraries. The separate analytics/models registry is not used by the deployed
classifier. Do not substitute a candidate, retrain, or load untrusted pickle
artifacts. The model's documented synthetic-data limitations still apply.

## Vercel project settings

1. Root Directory: repository root (`.`), where Dockerfile.vercel resides.
2. Framework Preset: **Container**. Minimal vercel.json pins `container` to
   override stale Python detection. Do not select Python, Vite, or Laravel hacks.
3. Clear existing Install Command, Build Command, Development Command, and
   Output Directory overrides; leave the Container defaults. All builds happen
   inside Docker. Do not set `public` as an output-directory override.
4. Enable/use Fluid compute for the container function. Set function duration
   to at least 180 seconds where the plan allows, since analytics alone permits
   120 seconds. Confirm current plan request-body, memory, duration and image
   limits with a representative synthetic ECR; PHP's 20 MB upload limit does
   not override an ingress limit.
5. Set runtime environment variables below separately for Production and
   Preview. Use separate databases/session cookie names for isolated previews.
6. Redeploy after including all new files and the existing model. If logs still
   select Python, check the deployed commit, Root Directory, and preset; for
   local CLI builds, refresh linked project settings and use a current CLI.

Vercel detects Dockerfile.vercel and supplies container routing; no manual
rewrite, Python entrypoint, or community PHP runtime is needed. Sources:
[container deployment](https://vercel.com/kb/guide/does-vercel-support-docker-deployments),
[container operation](https://vercel.com/kb/guide/docker), and
[official Container preset definition](https://github.com/vercel/vercel/blob/main/packages/frameworks/src/frameworks.ts).

## Runtime environment variables

Set these in the Vercel dashboard, never in Git or Docker build arguments.
Values in angle brackets are placeholders, not literal values to deploy.

```dotenv
APP_NAME="Naggasican NHS DSS"
APP_ENV=production
APP_KEY=<stable Laravel key generated securely once>
APP_DEBUG=false
APP_URL=https://<your-deployment-domain>
DB_CONNECTION=mysql
DB_HOST=<external-cloud-mysql-hostname>
DB_PORT=3306
DB_DATABASE=<dedicated-demo-database>
DB_USERNAME=<dedicated-application-user>
DB_PASSWORD=<secret>
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
SESSION_HTTP_ONLY=true
SESSION_COOKIE=naggasican_demo_session
CACHE_STORE=database
CACHE_PREFIX=naggasican_demo_
QUEUE_CONNECTION=sync
PYTHON_PATH=/usr/local/bin/python3
TRUSTED_PROXIES=*
LOG_CHANNEL=stderr
LOG_LEVEL=warning
PORT=8080
```

Leave SESSION_DOMAIN unset for host-only cookies. Keep APP_KEY stable across
instances/deploys; generate it with `php artisan key:generate --show` in a secure
terminal and place the output only into secret configuration. Never run ordinary
key:generate against an existing deployment to rotate its key inadvertently.
APP_URL must match the active HTTPS domain. Trusted proxy headers include only
forwarded client IP, protocol, and port, so URL generation sees HTTPS without
trusting arbitrary forwarded hosts. CSRF and authorization remain enabled.
Only set TRUSTED_PROXIES=* behind controlled ingress; local XAMPP leaves it empty.

Vite serves same-origin compiled assets; no ASSET_URL is required. VITE_ values
are public build-time values, not runtime secrets. This app currently needs no
custom VITE_ build inputs. To send password-reset mail, configure an external
SMTP service using MAIL_MAILER=smtp, MAIL_HOST, MAIL_PORT, MAIL_SCHEME,
MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS and MAIL_FROM_NAME. Otherwise
the existing log mailer is only appropriate for a controlled synthetic demo.
Keep existing DSS grading configuration decisions; do not set a fallback scheme
as a deployment workaround.

## External database and safe migrations

Use externally reachable MySQL with TLS, backups and connection capacity for
autoscaling. Never use 127.0.0.1, localhost, the XAMPP database, or SQLite files
for Vercel persistence. Existing config supports MYSQL_ATTR_SSL_CA: set it to
an actual mounted/bundled public CA certificate path when your provider requires
one (a path on your Windows machine will not work). Do not disable certificate
verification. Private CA provisioning/network allowlisting remains provider
specific. Use provider credentials through runtime secrets, not image files.

The database must contain the application's migrations, including sessions,
cache and cache_locks. QUEUE_CONNECTION=sync avoids dependence on a background
worker that cannot run durably in a request-scoped function. If switching to
queued processing later, provide a separately managed worker/service.

For a release, back up the target cloud database, review migrations, verify them
against a synthetic staging database, then run the following **once** from a
trusted machine/job with that target's environment securely injected:

```sh
php artisan migrate:status
php artisan migrate --pretend --force
php artisan migrate --force
php artisan dss:check-integrity
```

Do not use composer setup (it generates keys and runs migrations), migrate:fresh,
migrate:refresh, or blanket demo seeding. Inspect and provision required catalog
data and a demo account through an approved setup procedure. Existing local data
is neither exported nor copied. DB connectivity to a real cloud service has not
been tested because no cloud credentials were provided.

## File persistence: unresolved full-deployment blocker

Single-request grade imports pass PHP's temporary UploadedFile directly to
Excel and need no permanent source file. Analytics scratch JSON is also
request-local and deleted. Imported grades/history/results live in the database.

However, AssessmentController stores ECR files on `Storage::disk('local')`
during upload, reads the same file during subsequent mapping/preview/confirmation
requests, then deletes it on successful import. Admin StudentController's ECR
learner upload similarly retains a file between preview and confirm before
deletion. Abandoned previews can leave files until cleanup. These files are
temporary in purpose but **must survive across requests and instances**.

Consequently the complete application is not yet reliable on stateless Vercel.
Shared private object storage with expiration is required for these workflows.
Setting FILESYSTEM_DISK=s3 alone will not fix them: they explicitly select
`local` and call `path()`, requiring a local readable file for spreadsheet parsing.
A follow-up must retain the preview/confirm rules, store pending objects privately,
download each needed object to per-request scratch, and preserve cleanup and
ownership checks. The S3 Flysystem adapter is not currently a Composer dependency.
No storage redesign was made in this deployment-preparation change, as requested.
Do not represent a successful login/health check as proof these workflows work.

## Commands before pushing

With existing Windows PHP, Python, Composer and Node installed:

```powershell
composer install
npm ci
python -m pip install -r analytics/requirements.txt
php artisan optimize:clear
php artisan test
npm run build
php artisan dss:check-integrity
php artisan route:list
python analytics/test_classify.py
python analytics/test_training_pipeline.py
php -l bootstrap/app.php
php -l config/trustedproxy.php
git diff --check
Get-FileHash analytics/model_cache.pkl -Algorithm SHA256
git status --short
docker build -f Dockerfile.vercel -t naggasican-dss-vercel .
```

Run artisan tests with the supplied phpunit.xml SQLite `:memory:` settings; do
not override test DB settings to a real database. optimize:clear clears the
configured cache, so use the local development environment for this checklist.
The training-pipeline tests train only isolated synthetic test candidates and
do not replace the production model.

For a disposable no-database HTTP smoke test after building, PowerShell:

```powershell
$smokeBytes = New-Object byte[] 32
$smokeRng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
$smokeRng.GetBytes($smokeBytes)
$smokeRng.Dispose()
$smokeKey = 'base64:' + [Convert]::ToBase64String($smokeBytes)
docker run --rm -d --name naggasican-vercel-smoke -p 8090:9090 -e PORT=9090 -e APP_ENV=testing -e APP_DEBUG=false -e APP_KEY=$smokeKey -e APP_URL=http://localhost:8090 -e SESSION_DRIVER=array -e CACHE_STORE=array -e QUEUE_CONNECTION=sync -e SESSION_SECURE_COOKIE=false naggasican-dss-vercel
curl.exe --fail http://localhost:8090/up
curl.exe --fail http://localhost:8090/login
docker logs naggasican-vercel-smoke
docker stop naggasican-vercel-smoke
```

This tests a non-default PORT and server boot without touching MySQL. Then test
authentication, CSRF, assets, synthetic reports and imports against a dedicated
cloud staging database after resolving shared file storage. Review the diff and
stage deployment files plus analytics/model_cache.pkl explicitly when ready;
never stage `.env`, dumps, real student data or uploads. No automatic commit/push.

## Validation record

Local PHP was 8.2.12; Python was 3.14.6 with the exact requirements versions.

| Check | Result |
| --- | --- |
| php artisan optimize:clear | Passed |
| php artisan test | 992 passed, 3388 assertions |
| php artisan test --filter=DeploymentProxyTest | 2 passed, 3 assertions (added after full suite started) |
| npm run build | Passed, 63 modules; manifest/CSS/JS emitted |
| php artisan dss:check-integrity | Passed, no orphaned/inconsistent data |
| php artisan route:list | Passed, 101 routes |
| python analytics/test_classify.py | 3 passed |
| python analytics/test_training_pipeline.py | 16 passed |
| PHP syntax, vercel.json parse, shell LF, git diff --check | Passed |
| Production model checksum | Unchanged |
| Docker build/start | Not run: Docker unavailable |
| Cloud database / Vercel deployment | Not tested: no deployment credentials supplied |

Vite initially hit sandbox spawn EPERM; the training suite initially hit sandbox
temporary-directory access denial. Both passed when rerun with approved access.
PHPUnit reports three existing doc-comment metadata deprecation warnings; no
unrelated test/application changes were made for these warnings. Full test output
is local-only in storage/logs/vercel-php-tests.log (excluded from Git and Docker).
Docker image startup, Linux package linkage, base-image availability and Vercel
limits remain unverified until the exact image build and staging deployment.

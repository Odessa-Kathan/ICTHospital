# Patient age registration, clinic branding and HTTPS

## Requirements and impact review

Three independent reviews covered registration requirements, design and implementation. A further independent review covered branding and TLS impacts. Review findings led to defensive handling of malformed legacy dates, signed edit/year snapshots, removal of stale session branding and broken logo references, and preservation of the existing SMS sender identity.

New patient forms default to **I know only age**. Whole years 0–150 produce January 1 of the application year minus age, with an explicit `birthdate_estimated` flag. Unknown age/date remain optional; name-only registration and older DOB-based clients remain supported. Exact DOBs are never assumed estimated merely because they fall on January 1. Existing patient DOBs are not rewritten by the migration.

The server validates the selected mode and derives the DOB independently of JavaScript. Estimated edits preserve the original birth year when the entered age is unchanged, including across New Year. A newly calculated estimate from a form opened in a prior year requires reviewing the refreshed preview before saving. The patient record, list and wristband label estimates, as do clinical printouts.

`CLINIC_NAME` (default **Shreeji Clinic**) controls public pages, shared navigation, browser titles, verification and print headings. The existing medical mark is reused with escaped text. Technical identifiers, repository names and upstream license/copyright notices remain intact. `SMS_SENDER_MASK` can preserve the prior provider-approved sender independently of UI branding. Keep `APP_NAME` unchanged to preserve default session/cache namespaces.

## Verification

PHP feature tests live in `tests/Feature/PatientAgeRegistrationTest.php` and `ClinicBrandingTest.php`, alongside the existing patient/chart and application suite. Tests use isolated SQLite, never the production MySQL database. Browser checks exercise checked defaults, preview, mode transitions, zero age, error restoration, mobile branding and JavaScript-disabled submission against an isolated test app.

Run PHP tests with `php artisan test`. The reviewed release passed **282 tests / 1,002 assertions** on PHP 8.3 with isolated SQLite, plus **seven Chromium browser checks**, including JavaScript-disabled registration and a 320px mobile viewport. The initial Linux patient/chart subset also passed (32 tests). Deployment revision is recorded in the release handoff.

## Deployment order

1. Back up the deployed code, `.env`, Nginx config and production database to a private server directory. Record the current commit and row count.
2. Apply only the additive estimated-DOB migration before activating the updated registration code. Never run `migrate:fresh`, tests or demo seeders against production.
3. Prepare the reviewed code. Preserve all contact details, user credentials and patient records. Defer the branding/environment cutover until the production TLS certificate is ready.
4. With Certbot >=5.4, validate IP issuance using the shortlived staging profile and the live webroot; then issue the production certificate named `shreeji-clinic-ip` for 129.225.76.84. Staging certificates must never be served to users.
5. With the production certificate ready, briefly quiesce web requests/jobs for the cutover. Run `deploy/configure-clinic.php` as root to atomically preserve environment-file permissions/ownership while configuring branding, the prior SMS mask, HTTPS URL, secure cookies and mail display name, and updating only the singleton institute name. Install `deploy/nginx-shreeji.conf`, test before reload, rebuild Laravel config/views, restart queue workers and reload PHP-FPM, verify the effective SMS override, then resume requests. Keep `APP_NAME`, cookie name and cache prefixes unchanged. Database/filesystem changes are not jointly atomic across a crash: recover from the private pre-release backup if interrupted.
6. Install `deploy/reload-nginx-after-renewal.sh` in `/etc/letsencrypt/renewal-hooks/deploy/` and verify the Certbot renewal timer plus a dry run. IP certificates last approximately six days, so automatic renewal is mandatory. Leave HTTP port 80 and the ACME path available.
7. Verify the publicly served certificate without disabling trust checks; HTTP must redirect to HTTPS while preserving path/query, and the challenge path must remain directly reachable. Check authenticated registration, assets, CSRF, printing and secure cookies.

The Nginx configuration is specific to this existing Oracle VM/IP; adapt paths and identifiers for another environment. Rollback uses the private backup or recorded Git revision. The additive estimated-DOB column may remain during application rollback; dropping it would lose newly recorded estimation metadata.

References: [Let's Encrypt IP certificates with Certbot](https://letsencrypt.org/2026/03/11/shorter-certs-certbot.html), [short-lived certificates](https://letsencrypt.org/2026/01/15/6day-and-ip-general-availability/).

# Clockin

Clockin is a Laravel API-first attendance system with a responsive Vue browser client. The browser and future Flutter or React Native clients share the versioned REST API at `/api/v1`; attendance, authorization, face-provider integration, and geofence rules remain on the server.

## Requirements and setup

- PHP 8.3+
- Composer
- Node.js and npm

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
```

Seed the local database with demo accounts, workplaces, shifts, assignments, recent attendance history, and one pending correction request:

```sh
php artisan db:seed
```

Demo sign-in credentials (all demo users share the configured demo password):

| Role | Email |
| --- | --- |
| Admin | `admin@clockin.test` |
| Employee | `jordan@clockin.test` |
| Employee | `sam@clockin.test` |
| Employee | `taylor@clockin.test` |
| Employee | `casey@clockin.test` |

The default local/testing password is `DemoPass123!`; set `DEMO_PASSWORD` in `.env` before seeding to choose another. The demo seeder is idempotent and can be rerun to restore the demo accounts and sample attendance. It refuses to run in production. Demo accounts have no real face enrollment; verified clock actions remain disabled until a server-side face provider is configured.

Run the browser app and Laravel API in separate terminals:

```sh
php artisan serve
npm run dev
```

The API and browser client should share an origin in production. Local Vite development proxies `/api` and `/sanctum` to `http://127.0.0.1:8000`; set `LARAVEL_DEV_SERVER` if Laravel listens elsewhere. Configure `APP_URL` to the canonical app URL, and set `SANCTUM_STATEFUL_DOMAINS` and `FRONTEND_ORIGINS` for the exact frontend host(s). The Sanctum config always adds the host from `APP_URL` to the stateful list, even when `SANCTUM_STATEFUL_DOMAINS` is explicitly set. Never use wildcard browser origins with credentialed cookies. Production session cookies are Secure by default; local HTTP development may set `SESSION_SECURE_COOKIE=false`.

For the deployed demo, set `APP_URL=https://clock.survaxis.co.za`, include `clock.survaxis.co.za` in `SANCTUM_STATEFUL_DOMAINS`, and set `FRONTEND_ORIGINS=https://clock.survaxis.co.za`. After changing environment values, clear Laravel's cached configuration and restart the PHP application so the settings take effect.

## Authentication

### Browser

1. `GET /sanctum/csrf-cookie`.
2. `POST /api/v1/auth/login` with JSON `email`, `password`, and optional `remember` (defaults to `true`).
3. Keep the Laravel session cookie and send `X-XSRF-TOKEN` for state-changing requests. Axios in this app is configured to send credentials and the XSRF header.
4. `POST /api/v1/auth/logout` invalidates the session and remembered sign-in.

The browser sign-in keeps employees signed in on that device by default, including across browser restarts; set `remember` to `false` to opt out. The session cookie remains HTTP-only, SameSite=Lax, and Secure in production. Login is rate limited; stateful API requests pass through Laravel CSRF middleware.

### Native clients

`POST /api/v1/auth/tokens` with `email`, `password`, and `device_name`. The response contains a one-time bearer token with a 30-day expiry. Store it in the platform's secure credential store and send `Authorization: Bearer <token>`. `POST /api/v1/auth/logout` revokes the current token; `GET /api/v1/auth/tokens` and `DELETE /api/v1/auth/tokens/{tokenId}` support device management and revocation. Deactivated users are denied access and their existing tokens are removed when an administrator disables the account.

## API contract

The complete OpenAPI 3.1 contract, including multipart uploads, request/response shapes, pagination, authentication, and error responses, is in [docs/openapi.yaml](docs/openapi.yaml).

Main endpoint groups:

- Authentication/profile: `/auth/login`, `/auth/tokens`, `/auth/me`, `/auth/logout`
- Face enrollment and verification: `/face/enrollment`, `/face/verification`
- Assignments: `/workplaces/assigned`
- Attendance: `/attendance/status`, `/attendance/history`, `/attendance/clock-in`, `/attendance/clock-out`, `/attendance/break/start`, `/attendance/break/end`
- Corrections: `/attendance/corrections`
- Administration: `/admin/dashboard`, `/admin/employees`, `/admin/workplaces`, `/admin/shifts`, `/admin/corrections`, `/admin/reports/attendance`

Collections use Laravel API Resources and Laravel pagination metadata. API errors use a consistent machine-readable envelope:

```json
{
  "error": {
    "code": "validation_error",
    "message": "The submitted data is invalid.",
    "details": {
      "email": ["The email field is required."]
    }
  }
}
```

## Attendance and provider configuration

- Attendance timestamps and work dates come from the server. Client-supplied timestamps are not accepted for clock actions.
- Clock-in requires an active workplace assignment, coordinates inside the configured server-side radius, face enrollment, successful face verification, and an `Idempotency-Key` header. Clock-out and break actions revalidate location and identity.
- Idempotency keys are unique per user and stored with the request hash and original response. Replaying a request with the same payload returns the original response; changing the payload under the same key returns `409 idempotency_key_reused`. A unique `(user_id, work_date)` database constraint prevents duplicate daily attendance records.
- Attendance actions require a live network request; offline clock events are not accepted or queued.
- The app does not persist face images. The provider interface receives the upload in the backend. Implement `App\Services\Face\FaceProvider` for the selected vendor, configure its class through the server-only `FACE_PROVIDER` environment variable, and keep all provider credentials in server-side environment configuration. A browser/native vendor SDK can produce provider-specific inputs, but any final identity decision must be confirmed by this server provider. Until configured, face endpoints return `503 face_provider_unavailable`; attendance is intentionally unavailable rather than trusting a client-side match.
- Location coordinates are treated as untrusted inputs; the server computes distance to the assigned workplace. Browser geolocation requires a secure context (HTTPS outside localhost).

## Roles and administration

Users have `employee` or `admin` roles. Admin endpoints are protected by the same `administer` gate regardless of session or token authentication. Admins can manage employees, assign workplaces/shifts, manage geofences and shifts, review correction requests, and view attendance reports.

## Tests

```sh
php artisan test
npm run build
```

Feature tests cover browser session authentication, bearer-token issuance/revocation, common validation errors, geofence enforcement, and idempotent attendance requests.

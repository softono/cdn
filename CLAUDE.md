# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A lightweight, dependency-free S3-compatible storage API written in plain PHP (no framework, no Composer packages). It exposes S3-style bucket/object HTTP semantics (list buckets, CRUD on buckets/objects, copy-object, batch delete) backed by MySQL for metadata and the local filesystem for object bytes.

## Running locally

There is no build step, package manager, or test suite — this is raw PHP served through Apache (`mod_rewrite`).

- Requires PHP with `pdo_mysql`, Apache with `mod_rewrite`, and a MySQL database (generated columns in `objects` need MySQL 5.7+/MariaDB 10.2+).
- Document root is `public/`; `public/.htaccess` rewrites all non-file/non-dir requests to `public/index.php`, and forwards the `Authorization` header into `HTTP_AUTHORIZATION` (Apache normally strips it).
- Copy `.env.example` to `.env` and fill in `DB_*` credentials, `BASE_URL`, `MAX_UPLOAD_SIZE`. `.env` is loaded manually by `src/env.php` (`loadEnv()`) — no vendor autoloading exists.
- Initialize the database from `db.sql` (defines `buckets`, `api_keys`, `objects`). **`db.sql` DROPs and recreates all three tables on every run** — it's an initial-setup script, not a migration; never run it against a database that already has data.
- Object bytes are written under `storage/` (or `STORAGE_DIR` env override), organized as `storage/{bucket}/{YYYY}/{MM}/{DD}/{uuid}.{ext}` — note the on-disk filename is unrelated to the S3 object key; the key-to-file mapping lives only in the `objects.relative_storage_path` column. Uploads land in `storage/{bucket}/.tmp/` first and are `rename()`d into place, so readers never observe a partially-written object.
- `MAX_UPLOAD_SIZE` is deliberately set below the ~8MB threshold at which S3 SDKs (aws-cli, boto3) silently switch to multipart upload, since this API has no multipart support.

## Architecture

Single entry point, no router library: `public/index.php` parses `$_SERVER['REQUEST_URI']` into path segments and dispatches by segment count + HTTP method:

- `OPTIONS` on any path → CORS preflight, handled before routing/auth (browsers never attach signatures to preflight requests)
- 0 segments → bucket listing (`GET /`)
- 1 segment → bucket operations (`GET`/`HEAD`/`PUT`/`DELETE /{bucket}`, plus `GET ?location`, `POST ?delete` for batch object delete)
- 2+ segments → object operations, where everything after the first segment is rejoined into the object key (so keys may contain `/`)

All logic lives in `src/` as static-method "Helper" classes, required directly by `index.php` (no autoloader):

- `env.php` — `.env` parsing (`loadEnv()`) and lookup (`env()`)
- `log.php` — `Log`: assigns an `x-amz-request-id` per request (sent on every response), installs a global exception/error handler that logs full detail to `storage/logs/error.log` and returns only the request id to the client, and writes one line per request to `storage/logs/access.log` via a shutdown function. `Log::init()` must run before anything else so the handlers are installed for the rest of the request.
- `db.php` — `DB`: singleton PDO wrapper (`fetchOne`/`fetchAll`/`execute`), MySQL only. `DB::getConnection()` exposes the raw PDO for transaction control (`beginTransaction`/`commit`/`rollBack`) used by the write paths in `src/s3/objects.php`.
- `response.php` — `Response`: all HTTP output goes through here (S3-style XML errors, XML bodies, HEAD responses, conditional GET → 304, single-range GET → 206, optional `X-Sendfile` handoff, chunked file streaming). Every response path ends in `exit`. Always sends `X-Content-Type-Options: nosniff`.
- `auth.php` — `Auth`: authentication only, no authorization beyond bucket tenancy. Supports `AWS4-HMAC-SHA256` (SigV4, header or query-string presigned) and anonymous GET/HEAD when the bucket's `visibility` is `public`. Canonicalizes the query string from the raw `QUERY_STRING` rather than `$_GET`, since PHP collapses duplicate query keys and mangles `.` to `_` in parameter names. `authenticate()` enforces that API keys scoped to a `bucket_id` (in `api_keys`) can't touch other buckets, and sets the request's log principal via `Log::setPrincipal()`.
- `s3/common.php` — `S3Common`: shared s (`generateId()`, `getStorageBaseDir()`) used by the bucket/object s below.
- `s3/bucket.php` — `S3Bucket`: bucket CRUD, CORS header emission (`applyCorsHeaders()`, driven by the bucket's `cors_origins` JSON column), `?location`.
- `s3/objects.php` — `S3Object`: object CRUD, copy, batch delete. Uploads/copies are streamed in 1MB chunks to a temp file (MD5 checksum computed on the fly, enforced against `MAX_UPLOAD_SIZE`), then `rename()`d into place inside a DB transaction — the DB row and the file on disk change atomically from a reader's perspective, and a superseded file is only unlinked after commit. Lookups use `object_key_hash` (a generated `SHA2(object_key, 256)` column) rather than `object_key` directly, since `object_key` (up to 1024 chars) is too long to index directly under `utf8mb4`.
- `s3/listing.php` — `S3Listing`: `ListObjects` V1 and V2, including `delimiter`/`CommonPrefixes` and honest `IsTruncated`/`NextMarker`/`NextContinuationToken` (a listing is never reported complete unless the query has verifiably exhausted the bucket).

Routing calls `Auth::authenticate()` before any privileged  call — when adding a route, follow that same order (auth before mutation) since the `S3*` methods themselves do not re-check auth.

`S3Object::validateObjectKey()` is the only path-traversal guard for object keys (rejects `..`, a leading `\\`, and keys over 1024 chars) — any new code path that turns an object key into a filesystem path must call it first.

## Accepted risks (deliberate, not oversights)

- `api_keys.secret_key` is stored in plaintext — required since SigV4 needs the raw secret to derive the signing key, and the tradeoff of encrypting it at rest was explicitly declined.
- No permission model beyond bucket scoping — a valid API key can do anything within the bucket(s) it's scoped to. Mitigate by issuing one key per bucket per trust level.
- No multipart upload — `MAX_UPLOAD_SIZE` is kept below the SDK multipart threshold instead.
- No automated tests and no migration runner — `db.sql` is edited in place; schema changes are applied by hand.
- No storage-quota or free-disk-space enforcement — `buckets.storage_quota`/`used_bytes` do not exist; disk exhaustion is an operational/monitoring concern, not handled by the application.

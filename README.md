# CDN

A lightweight, dependency-free S3-compatible storage API written in plain PHP. No framework, no Composer packages, no build step — just PHP served through Apache (`mod_rewrite`), with MySQL for metadata and the local filesystem for object bytes.

## Requirements

- PHP with the `pdo_mysql` extension
- Apache with `mod_rewrite`
- MySQL 5.7+ / MariaDB 10.2+ (generated columns are used in `objects`)

## Getting started

### 1. Point Apache at `public/`

The document root must be the `public/` directory — `public/.htaccess` rewrites all non-file/non-dir requests to `public/index.php` and forwards the `Authorization` header (Apache strips it by default).

### 2. Configure environment

Copy `.env.example` to `.env` and fill in your values:

```bash
cp .env.example .env
```

```
APP_NAME=CDN
APP_UID=cdn
APP_TIMEZONE=UTC
BASE_URL=http://localhost

# Absolute path for object storage. Leave unset to default to <project>/storage
# STORAGE_DIR=/var/www/cdn/storage

MAX_UPLOAD_SIZE=8388608

DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=cdn
DB_USERNAME=cdn_usr
DB_PASSWORD="secret_password"
```

`.env` is loaded manually by `src/env.php` — there is no vendor autoloader.

### 3. Create the database and import the schema

Create an empty database matching `DB_DATABASE`, then import `db.sql`:

```bash
mysql -u cdn_usr -p cdn < db.sql
```

> **Warning:** `db.sql` **drops and recreates** `buckets`, `api_keys`, and `objects` on every run. It's an initial-setup script, not a migration — never run it against a database that already has data, or you will permanently lose every bucket/key/object row (object bytes on disk are not removed and become orphaned).

This creates three tables: `buckets`, `api_keys`, `objects`.

### 4. Create a bucket

Insert a row into `buckets`. `id` is a UUID (v4) you generate yourself — there's no DB-side UUID generation.

```sql
INSERT INTO buckets (id, name, visibility, cors_origins)
VALUES (
  UUID(),
  'my-bucket',
  'private',           -- or 'public' to allow anonymous GET/HEAD
  NULL                 -- or JSON array of allowed origins, e.g. '["https://example.com"]'
);
```

### 5. Create an API key

Insert a row into `api_keys`, scoped to the bucket you just created (or `bucket_id = NULL` for an unrestricted key that works across all buckets). `access_key`/`secret_key` are plain values you choose — there's no key-generation endpoint.

```sql
SET @bucket_id = (SELECT id FROM buckets WHERE name = 'my-bucket');

INSERT INTO api_keys (id, title, bucket_id, access_key, secret_key, status)
VALUES (
  UUID(),
  'my-bucket local key',
  @bucket_id,
  'AKIAEXAMPLEACCESSKEY',   -- access key ID
  'exampleSecretKeyValue',  -- secret key 
  'active'
);
```

### 6. Make a request

The API speaks S3-style `AWS4-HMAC-SHA256` (SigV4) auth, so any S3-compatible client works — point it at `BASE_URL` with the access/secret key pair from step 5. For example, with `aws-cli`:

```bash
aws --endpoint-url http://localhost/ \
    --profile my-bucket \
    s3 cp ./file.txt s3://my-bucket/file.txt
```

(`aws configure --profile my-bucket` first, using the access/secret key from step 5.)

Buckets marked `visibility = 'public'` also allow anonymous `GET`/`HEAD` without any signature.

### Protected buckets & signed URLs

- `visibility = 'private'` (the default) is **protected mode**: every request — including `GET`/`HEAD` — must carry a valid SigV4 signature. There is no anonymous access at all.
- `visibility = 'public'` allows anonymous `GET`/`HEAD`, but every other operation (`PUT`, `DELETE`, listing with credentials, etc.) still requires a valid signature.

For a private bucket, share time-limited read access to an object without handing out the API key by generating a **presigned URL** — a signed query string with a built-in expiry (`X-Amz-Expires`), which the API validates on every request ([src/auth.php](src/auth.php)). With `aws-cli`:

```bash
aws --endpoint-url http://localhost/ \
    --profile my-bucket \
    s3 presign s3://my-bucket/file.txt \
    --expires-in 3600
```

This prints a URL like `http://localhost/my-bucket/file.txt?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=...&X-Amz-Expires=3600&X-Amz-Signature=...`, which works from a plain browser/`curl` until it expires — no `Authorization` header needed. Requests past the expiry window get `403 AccessDenied`, and a tampered/incorrect signature gets `403 SignatureDoesNotMatch`.

## Notes

- No multipart upload support — `MAX_UPLOAD_SIZE` is kept below the ~8MB threshold at which S3 SDKs silently switch to multipart, so uploads above that limit are rejected rather than silently mishandled.
- No permission model beyond bucket scoping — any active key can do anything within the bucket(s) it's scoped to. Issue one key per bucket per trust level.
- Logs are written to `storage/logs/access.log` and `storage/logs/error.log`.

See [CLAUDE.md](CLAUDE.md) for architecture details.

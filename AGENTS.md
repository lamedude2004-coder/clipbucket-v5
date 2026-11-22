# Running ClipBucket V5 in this sandbox

`docker-compose.base44.yml` + `Dockerfile.base44` run the app from the checkout
(bind mount) on port 3000, with MariaDB 10.11 in its own service.

```sh
docker compose --project-name app --project-directory "$BASE44_PROJECT_ROOT" \
  -f "$BASE44_PROJECT_ROOT/docker-compose.base44.yml" up -d --build
```

Non-obvious setup facts:

- **The repo ships `upload/files/temp/install.me`** (the installer lock). Once the
  database is installed that file makes `check_install()` force maintenance mode,
  which renders only the "we are updating our website" banner. The container
  entrypoint creates `install.me.not` next to it (untracked, gitignored) to mark
  the install finished — do not delete the tracked `install.me`.
- **nginx talks to PHP-FPM over `/run/php/php8.3-fpm.sock`**; the official image
  defaults to TCP 9000, so `Dockerfile.base44` adds
  `/usr/local/etc/php-fpm.d/zzz-clipbucket.conf` to override it.
- **`base_url` is patched at every start** to the public preview origin
  (`https://3000-$BASE44_PUBLIC_HOST_SUFFIX/`) only when `BASE44_PREVIEW_MODE=1`.
  Unset, the value in the database is left untouched.
- The sandbox checkout is mode 0700; the entrypoint `chmod o+rx` the app root and
  chowns only `upload/files` and `upload/cache` to `www-data` (no chown of source).
- Installer ran once against the `db_data` volume; admin account is `admin`
  (`admin@example.com`). Reset the volume only if you want a fresh install.
- **Email verification is off** (`cb_config.email_verification = 0`), so signup logs the
  user in immediately. `user.class.php::signup_user()` previously called
  `hasPermissionOrRedirect('admin_access', true)`, which bounced guests to the
  login page right after signup; it now uses `hasAdminAccess()` (still skips the
  welcome email for admin-created accounts). Setting `email_verification = 1`
  brings the activation-by-email flow back.
- **Uploaded videos are stored on Vidara.so** when `VIDARA_API_KEY` is present
  (delivered from `/run/base44/app.env`, wired into the `web` service). The
  conversion CLI (`upload/actions/video_convert.php`) then uploads the temp file
  instead of running ffmpeg: `Vidara::process()` stores the file, builds the
  thumbnails from the local copy, and marks the video `Successful` with
  `remote_play_url = vidara:<filecode>`. The existing player is unchanged —
  `remote_play::get_video_url()` resolves the marker to a fresh signed HLS URL
  from `https://vidara.to/api/stream` on each watch-page render (the signed URL
  is short lived). Without the key, the normal local conversion runs.
- **`/usr/bin/php` is symlinked to the image's `php`** because
  `config('php_path')` points there and background jobs (conversion) call it.
- Verify: `curl -sI http://localhost:3000/` should be 200 with a >30 KB body
  (a small body means maintenance mode is back), and
  `/admin_area/` should redirect to its login page.

[![CI](https://github.com/JuzoSuzuya20/OpenTransfer/actions/workflows/ci.yml/badge.svg)](https://github.com/JuzoSuzuya20/OpenTransfer/actions/workflows/ci.yml) [![license](https://img.shields.io/github/license/JuzoSuzuya20/OpenTransfer)](LICENSE)

# OpenTransfer

**A self-hosted file sharing platform — a maintained fork of [Erugo](https://github.com/ErugoOSS/Erugo).**

Erugo is no longer maintained upstream. OpenTransfer keeps it alive with up-to-date dependencies,
security fixes and a continuous integration pipeline that tests every change and publishes a
ready-to-run Docker image.

Built with PHP/Laravel and a Vue.js frontend. Files stay on your own infrastructure, with SSO
support (OpenID Connect, Microsoft Entra ID, Google, Authentik).

### What changed from Erugo

- Laravel 13 and PHP 8.5 (Laravel 11 no longer receives security fixes)
- All known vulnerabilities in PHP and JavaScript dependencies fixed
- Production image on Alpine 3.24, without development dependencies or unused PHP extensions
- Third-party binaries (tusd) verified against pinned checksums
- Container health check on `/up`
- No root at runtime: Caddy, PHP, tusd, the queue worker and the scheduler run as `erugo` (uid 1000)
- No third-party requests (fonts and CSS are served by the app), Content-Security-Policy and strict security headers
- Images published to `ghcr.io` for `amd64` and `arm64`, scanned with Trivy and signed with cosign
- Optional allowlist of the networks members may sign in from, and `TRUSTED_PROXIES` for reverse proxies
- Optional two-factor authentication for password sign-in, which administrators can make mandatory
- Optional encryption at rest of shared files, with a key kept outside the storage volume
- Optional self-hosted anti-robot check on sign-in, password reset and registration

## Quick Start

```yaml
services:
  app:
    image: ghcr.io/juzosuzuya20/opentransfer:latest
    restart: unless-stopped
    volumes:
      - ./opentransfer-storage:/var/www/html/storage # Use a dedicated folder
    ports:
      - "9998:80"
```

```sh
docker compose up -d
```

This starts OpenTransfer with a default SQLite database. Put it behind a reverse proxy with TLS for
production, and configure an SMTP server for email notifications.

### Migrating from Erugo

OpenTransfer uses the same storage layout and database as Erugo. Replace the image name
(`wardy784/erugo` → `ghcr.io/juzosuzuya20/opentransfer`) and keep your existing storage volume;
database migrations run automatically on startup. Back up the storage folder first.

Active sessions are invalidated once after upgrading (session data is now stored as JSON), so users
will need to sign in again.

### Hardened deployment

Root is only used at startup, to fix the ownership of the storage volume. To avoid it entirely and lock
the container down:

```yaml
services:
  app:
    image: ghcr.io/juzosuzuya20/opentransfer:latest
    restart: unless-stopped
    user: "1000:1000"          # the storage folder must belong to uid 1000
    read_only: true
    tmpfs:
      - /tmp
    cap_drop: [ALL]
    security_opt:
      - no-new-privileges:true
    volumes:
      - ./opentransfer-storage:/var/www/html/storage
    ports:
      - "9998:80"
```

### Behind a reverse proxy

Behind a proxy, every request appears to come from the proxy. Tell the container which address to
believe, so that rate limits and the IP allowlist work on the real client address:

```yaml
    environment:
      - TRUSTED_PROXIES=192.0.2.1   # or several, separated by commas: 192.0.2.1,10.0.0.0/24
```

- List the proxy only, not the whole network: a client inside a trusted range can forge its address.
- `*` trusts whoever connects directly. Only use it if nothing but the proxy can reach the container.
- The proxy must append to `X-Forwarded-For` (Caddy, Traefik and nginx with
  `$proxy_add_x_forwarded_for` do).
- This setting changes the client address and nothing else: the SSO callback URLs stay the same.
- An invalid value stops the web server from starting, with the reason in `docker logs`.

Settings → System → Security shows the address the application sees for you: it must be yours, not the
proxy's.

### Restricting sign-in by network

Optional, off by default, in Settings → System → Security. Members can then only sign in (password or
SSO) and keep using their session from the listed addresses and ranges. Share links and reverse-share
guests stay reachable from everywhere. An upload already authorized can still send its data until its
token expires (60 minutes at most), but cannot be turned into a share.

The settings screen refuses a list that does not include your own address. If you lock yourself out
anyway, for example because your address changed:

```sh
docker exec <container> ot-artisan disable-ip-allowlist
```

Use `ot-artisan` for artisan commands: `docker exec` does not inherit the environment the application
runs with, so plain `php artisan` would look for another database.

### Two-factor authentication

Each user can add a second step to password sign-in in Settings → My profile: a code from an
authenticator app or a passkey (fingerprint, face, PIN or security key), with single-use recovery codes.
Administrators can make it mandatory for admins or for everyone in Settings → System → Security: users
concerned set it up at their next password sign-in.

- SSO sign-in does not change: it is protected by your identity provider, which should enforce its own
  second factor.
- Reverse-share guests are not concerned.
- Passkeys are bound to the domain the application is reached on, and need HTTPS (or localhost).
  Changing the domain means adding them again.
- Administrators can also allow signing in with a passkey alone, without a password: the device must
  then verify the user (biometrics or PIN).
- Resetting a password no longer opens a session for an account with a second factor.
- Five failed attempts lock the second factor of the account for 15 minutes.
- Secrets are encrypted with the app key, and the codes hashed: back up `storage/app.key` with the
  database, as a restored database cannot be read without it.

If someone loses their phone and their recovery codes, an administrator can remove their second factor
in Settings → Users, or on the server:

```sh
docker exec <container> ot-artisan reset-2fa user@example.com
```

### Anti-robot check

Optional, off by default, in Settings → System → Security. The browser solves a short proof of work (SHA-256,
in the ALTCHA format) before sign-in, password reset requests and account creation: it costs a bot for every
attempt and goes unnoticed by people. Everything is self-hosted, no request leaves your server.

It slows down mass attempts and adds to the limits on failed sign-ins, it does not replace them: a dedicated
attacker can pay this cost. SSO is not concerned. Scripts calling these routes directly have to solve it too.

### Encrypting stored files

Optional, off by default. Shared files can be encrypted on disk (XChaCha20-Poly1305, 1 MiB authenticated
chunks), so that a copy of the storage volume or of a backup cannot be read on its own. It protects
against the loss of a disk or a backup, not against someone who controls the running server.

> **Losing the key means losing the encrypted files, for good.** Nobody can recover them. Keep a copy of
> the key away from this server, in your password manager or vault, before encrypting anything. Do not
> keep it in the storage volume: the protection would be gone.

1. Generate a key: `docker exec <container> ot-artisan generate-storage-key`
2. Copy it somewhere safe, then give it to the container:

   ```yaml
       environment:
         - STORAGE_ENCRYPTION_KEY=the-generated-key
   ```

3. Turn on Settings → System → Security → *Encrypt new shares*.
4. Optional, for the shares created before: `docker exec <container> ot-artisan encrypt-existing-files`

Plain and encrypted files can live side by side: older shares stay readable either way. To stop, untick the
setting, run `ot-artisan decrypt-files` and only then remove the key. To change the key, decrypt with the old
one, then encrypt with the new one. Both commands can be run again after an interruption.

If the key is missing or wrong, encrypted files stay safe on disk but their downloads fail, and new shares
are stored in plain text with a critical line in the logs: Settings → System → Security shows it.

Not covered: uploads in progress, which are encrypted once the share is created, and the database (names of
files, users).

### Verifying the image

Every published image is signed by this repository's CI (keyless, Sigstore):

```sh
cosign verify ghcr.io/juzosuzuya20/opentransfer:latest \
  --certificate-identity-regexp '^https://github.com/JuzoSuzuya20/OpenTransfer/' \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com
```

## Key Features

### Effortless Uploads
- Multiple file support with progress tracking
- Folder uploads that maintain structure
- Large file support with automatic resume
- Drag and drop interface

### Human-Friendly Share Links
- Generate memorable URLs like `you.com/shares/quiet-cloud-shrill-thunder`
- No more cryptic strings — easy to share and remember

### Security & Access Control
- Optional password protection for shares
- Configurable expiration dates
- Download limits per share
- Only authorized users can create shares

### Customizable Branding
- Built-in themes (light and dark modes)
- Custom themes via the web interface
- Logo and color customization
- Easy white-labeling for your organization

### Powerful Dashboard
- Track downloads and monitor activity
- Manage shares, extend expiration, or revoke access
- Easy user management with invite system

### Additional Features
- **Reverse Share** — Invite guests to upload files to your account with a one-time token
- **Email Support** — Full email support for notifications, password reset, and more
- **Interactive Setup** — User-friendly first-run configuration
- **Zero-Configuration** — Reasonable defaults out of the box
- **Automatic Cleanup** — Shares automatically deleted after expiration
- **Translations** — Fully translatable with multiple languages supported

## How It Works

1. **Upload** — Drag and drop files or select them from your device
2. **Configure** — Set optional password protection, expiration dates, and download limits
3. **Share** — Get a clean, human-readable URL to share with anyone
4. **Manage** — Track downloads, revoke access, or extend expiration dates from your dashboard

## Credits

OpenTransfer is based on [Erugo](https://github.com/ErugoOSS/Erugo), created by
[Dean Ward](https://github.com/DeanWard) and released under the MIT license. Thanks to him and to
Erugo's contributors for the original work.

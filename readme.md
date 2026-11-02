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
- Images published to `ghcr.io` for `amd64` and `arm64`

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

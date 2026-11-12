# Changelog

Every notable change to OpenTransfer. Versions follow [Semantic Versioning](https://semver.org/):
a major version for a change that needs action on an existing instance (data, configuration), a minor
version for a large update, a patch version for fixes. Each version is a `vX.Y.Z` tag, and the image is
published as `ghcr.io/juzosuzuya20/opentransfer:X.Y.Z` and `:X.Y`; `:latest` follows `main`.

## [1.0.0] — 2026-11-12

The first version of OpenTransfer, the maintained fork of [Erugo](https://github.com/ErugoOSS/Erugo).
An existing Erugo instance keeps its storage and database as they are.

### Added

- Mandatory SSO for the organisation's e-mail domains, with password sign-in kept for administrators
  (two-factor required); Keycloak provider with a setup wizard, a connection test and single logout
- Two-factor authentication for password sign-in (authenticator app, recovery codes, passkeys), which
  administrators can make mandatory; optional passwordless sign-in with a passkey
- Allowlist of the networks members may sign in from, and `TRUSTED_PROXIES` for reverse proxies
- Optional encryption at rest of shared files, with a key kept outside the storage volume
- Optional self-hosted anti-robot check on sign-in, password reset and registration
- E-mails in the design of the app and in seven languages, rewritable by the administrator language by
  language; each account keeps its language
- Spanish interface, and seven complete languages (English, French, German, Spanish, Italian, Dutch,
  Portuguese)
- DiceBear "Line Face" avatars

### Changed

- A new interface: one identity, dark or light after the system; a page for every section; selects,
  number fields and dialogs drawn by the app instead of the browser's; motion that decelerates in,
  accelerates out and answers with a small spring, reduced when the system asks for less motion
- The About page points to OpenTransfer, and shows the version the image was built from
- Laravel 13 and PHP 8.5; Alpine 3.24 image without development dependencies, with no process running
  as root, scanned with Trivy, signed with cosign, for `amd64` and `arm64`
- No third-party requests; Content-Security-Policy and strict security headers

### Security

- E-mail templates rendered in a Twig sandbox (code execution by an administrator)
- SMTP credentials no longer readable by every signed-in account
- Share identifiers no longer guessable (they had about 26 bits of randomness)
- Rate limits on sign-in, codes and passwords; inactive or unverified accounts refused
- Folder archives protected against zip bombs, ZIP archives built without following symbolic links
- Privilege flags no longer mass-assignable; exception details no longer returned to clients
- tusd hooks reachable only from inside the container
- All known vulnerabilities in the PHP and JavaScript dependencies fixed

[1.0.0]: https://github.com/JuzoSuzuya20/OpenTransfer/releases/tag/v1.0.0

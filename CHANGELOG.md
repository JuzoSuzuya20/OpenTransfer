# Changelog

Every notable change to OpenTransfer. Versions follow [Semantic Versioning](https://semver.org/):
a major version for a change that needs action on an existing instance (data, configuration), a minor
version for a large update, a patch version for fixes. Each version is a `vX.Y.Z` tag, and the image is
published as `ghcr.io/juzosuzuya20/opentransfer:X.Y.Z` and `:X.Y`; `:latest` follows `main`.

## [1.2.0] — 2026-11-13

### Added

- A file request can be a link instead of an e-mail: on the requests page, name the request, choose
  how long it lasts (1 to 30 days), copy the link and pass it on yourself. Nothing is e-mailed, the
  link opens once, like an e-mailed request
- E-mailed requests can now last 1 to 30 days as well, instead of always 7
- The guest who opens a request sees, above the drop zone, who asks for the files, their message and
  the date to drop them by
- The list of waiting requests tells which went by link, and gives a new link for one

## [1.1.6] — 2026-11-13

### Added

- "Delete expired shares" is followed by a progress bar: the shares deleted so far, the rows being
  deleted, then how many were deleted, instead of a message saying it was done before it was

### Fixed

- "Delete expired shares" also deletes the files guests dropped for the member: they were announced
  as deleted and stayed

## [1.1.5] — 2026-11-13

### Added

- A burst of confetti of every colour when files are dropped on the send area, and when a share or
  one of its files is downloaded; nothing when the system asks for less motion

### Fixed

- In the quick send window, the sides of the drop zone were cut off while a file was dragged over it

## [1.1.4] — 2026-11-13

### Fixed

- The tabs of the administration no longer move the window: a tab with an anchor scrolled down to its
  part and the next one scrolled back to the top. A tab now shows its part in place

## [1.1.3] — 2026-11-13

### Fixed

- The administration page saves again after a first save: an emptied text field (the SSO domains,
  the IP allowlist) came back as the text "null" and refused every later save ("Not a domain
  name: null"); instances already in that state save again without any action

## [1.1.2] — 2026-11-13

### Fixed

- Reloading a page no longer shows the sign-in screen for an instant before the page
- An emptied number field of the administration no longer breaks anything: an empty longest time
  online (« Empty: no limit ») refused every share, and an empty warning delay or file deletion delay
  crashed the hourly jobs
- Folders sent with their subfolders, and files dropped by the guest of a file request, are cleaned
  away when they expire: they used to stay on the disk, and the member who asked for the files is
  now told
- A share whose files were cleaned away can no longer be extended back to an empty active share
- Until a mail server is set up, an e-mail is set aside with one line saying why, instead of failing
  three times with an error
- Eight administration messages showed their raw key; sign-in, registration and the new password
  are real forms, which password managers save and fill
- DiceBear avatars without a deprecated call, faces unchanged

## [1.1.1] — 2026-11-13

### Fixed

- The link of a file request works for the seven days of the request: it stopped working an hour
  after it was sent
- The messages shown after a round trip to the identity provider are in the language of the screen
- "Extend" keeps a share within the longest time the administrator allows

### Security

- A session lasts seven days from its sign-in, renewals included (it lasted 60 days, renewed at every
  use); sessions open today get their week at their next renewal
- An address the identity provider has not verified no longer opens or creates an account by e-mail
  matching (`trust_email`, `allow_registration`); accounts already linked are unchanged. Make sure
  your provider marks the addresses of its accounts as verified (Keycloak: "Email verified", or
  "Trust Email" on an LDAP federation)
- The guest account of a file request only drops files: it no longer sends requests, changes its
  account or sets up a second factor
- A disabled account loses the API, its file requests and uploads at once
- An upload must declare its size, so the largest share size can no longer be bypassed
- Forms that send e-mails are paced: one password reset or code a minute per address, ten requests a
  minute per network address, twenty file requests a minute per account, fifty recipients per share
- Keys, database and backups are readable by the application only; secrets passed in addresses
  (share passwords, tokens) are masked in the web server log

## [1.1.0] — 2026-11-12

### Added

- "New share" opens a quick send window; "More options" carries the files over to the Send page
- A sign-in background image, under Administration → Customisation
- A 404 page, and error pages in the design and the language of the instance
- An eye button on every password field
- My shares updates by itself, without reloading the page
- Changing the address of an account now asks for a code sent to the new address

### Changed

- The Send, File requests and Administration pages are redesigned; the administration has a sidebar
  grouped by Instance, Access and Data, with shorter help texts in every language
- Statistics: the four figures share one band; sizes everywhere are in the units of the language (Mo, Go)
- The language menu of the sign-in screens sits in the top right corner; toasts close again
- The first page loads about a third of what it did (138 kB instead of 394 kB compressed): languages,
  pages, avatars, the zip library and the QR code arrive when they are needed, and only Bootstrap's
  base is kept
- In the "pattern" mode of share links, the presets and the default are longer, and the screen shows
  how much chance a pattern carries

### Security

- A share behind a password shows nothing of its name, message, size or files before the password;
  the password travels in the body of the request, never in an address
- Signing out ends both the access token and the refresh cookie on the server; the cookie is
  `Secure` when the instance is served over HTTPS
- A pattern of share links under 45 bits of chance is refused (one saved before falls back to
  `********`), and looking up shares that do not exist is rate limited
- Uploaded backgrounds keep the extension of their content, and the files the web server serves from
  uploads carry a sandboxing Content-Security-Policy
- phpseclib 3.0.58 (GHSA-w3p9-g4p2-h3rr)

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

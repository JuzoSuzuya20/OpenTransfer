# Changelog

Every notable change to OpenTransfer. Versions follow [Semantic Versioning](https://semver.org/):
a major version for a change that needs action on an existing instance (data, configuration), a minor
version for a large update, a patch version for fixes. Each version is a `vX.Y.Z` tag, and the image is
published as `ghcr.io/juzosuzuya20/opentransfer:X.Y.Z` and `:X.Y`; `:latest` follows `main`.

## [1.5.1] — 2026-11-17

### Security

- The SMTP password and the client secrets of identity providers are stored encrypted with the key
  of the instance, and never sent back to a browser, not even an admin's: the screen says one is
  saved, and a field left empty keeps it. They were stored in clear and sent back to admins; saving
  the SMTP settings returned the new password and the former one, which stayed in the database
- The code a self-registered account confirms its address with is stored hashed, as the code of an
  email change already was
- An existing instance gets them encrypted and hashed at its next start (a code sent before keeps
  working). They depend on the key of the instance (storage/app.key), as the 2FA secrets do: with
  another key, enter them again

### Added

- The main colour of the instance is chosen in Appearance, among seven (the blue violet of
  OpenTransfer by default, blue, emerald, orange, raspberry, violet, slate): buttons, links and the
  mark take it, in the light theme as in the dark one, and so do the emails. Each colour keeps
  coloured text and the white of buttons readable (4.5:1 at least); a colour tried and not saved
  goes when leaving the page. The page arrives in the saved colour, without a flash of the default
- Everyone chooses their theme in the account menu, on one line with three icons: the system's (by
  default), light or dark. The choice is kept by the browser and set before the page shows; every
  colour changes at once, in one crossfade of the page (at once without it when less motion is
  asked for), and so when the system turns dark or light

### Changed

- The logo is a square, without text, and the name next to it is the one the admin chose (General >
  Application name), as typed: in the header, on the sign-in page and in the emails. "OpenTransfer"
  was written next to the mark whatever the name, and an uploaded logo showed without the name
- The logo shipped is a square mark (512 × 512) instead of a banner with "OpenTransfer" written on
  it. An instance that kept a shipped logo (the banner of OpenTransfer or of Erugo) gets the square
  one at its next start; a logo an admin uploaded stays, held in the square without being stretched
- The width of the logo is no longer set: the logo is square
- The name of the default logo is no longer "erugo-logo.png"
- What opens or closes on a page grows and shrinks instead of appearing at once: the options and
  the name and message of a share in Send, the settings that depend on another one (link pattern,
  self-registration, anti-robot check), the edition of an identity provider, the link of a file
  request, the table of the statistics. Opening decelerates, closing goes sooner and accelerates;
  with less motion asked for, it only fades
- What loads shows its shape meanwhile (skeletons) instead of an empty page or a "Loading" line:
  shares, file requests, users, identity providers, backups, statistics, email templates, the
  profile, About, the settings of System and Appearance (their fields no longer change under the
  eyes), and the page of a share

### Fixed

- Refreshing the backups no longer makes its button flicker: it kept no icon, took a spinner and
  the colour of a disabled button for an instant. Its icon now turns, one turn at least, and the
  button keeps its size and colour

- About showed as "largest upload" the limit PHP sets on a single request (4 GB), which uploads
  never meet since they go through tusd in pieces: it shows the largest share the instance accepts,
  its setting
- About wrote the size of the shares over a bar of the whole disk (the system and other data
  included): the bar now tells the shares, what else the disk holds ("Used outside the shares": the
  system and other software, and the database, backups and logs of OpenTransfer) and what is free
  apart

## [1.5.0] — 2026-11-16

### Security

- A new password ends the sessions opened before it: a session left on another computer, or a
  cookie someone took, kept working until the end of its week, even after a reset by link. The
  session that changes its password goes on
- An upload left unfinished is offered again to the account that started it only, and sign-out
  clears it: on a shared computer, the next account saw its file names and message
- The routes of the themes are gone (the page no longer reads themes; one of them installed an
  uploaded file), and the download of a share answers GET and POST only

### Changed

- The names of Erugo give way to OpenTransfer where they were only names: the user of the image
  (`opentransfer`, uid 1000 as before, so existing volumes keep their owner), `OPENTRANSFER_PORT`
  (`ERUGO_PORT` is still read), the log (`storage/logs/opentransfer-*.log`), the files of the image
  and of the development setup. Erugo is still named where OpenTransfer says it is its fork, and
  where an instance moved from Erugo needs it
- The themes of Erugo are no longer seeded at each start: no page reads them, and their file only
  worked on SQLite. Those an instance holds stay in its database

### Removed

- Files that did nothing in the repository: a copy of `artisan` named `erugo`, the donation button
  and the screenshots of Erugo, an ignored `.dockerignore`, an unused help page, dead front-end
  modules, debug traces, 171 unused translation keys; the files of local tools (assistants, design
  tools) are ignored

## [1.4.0] — 2026-11-16

### Added

- A file request can be cancelled from the list of waiting requests ("Remove" for an expired one):
  its link stops working at once, even for a guest who already opened it. A request that came back
  stays, its files being a share
- A link of a file request that no longer opens leads to a page of its own, laid out like the
  download page, instead of a sign-in form and a short message: already used, expired (until when
  it was valid) or no longer valid (cancelled, or not copied in full), each time with whom to ask
  for a new link
- Like the "force" option of ITSM-NG, the sign-in page can lead straight to one identity provider
  (Administration > SSO > Sign-in page). Local accounts (admins) keep the form at `/?noAUTO=1`; the
  form also shows once after signing out on purpose, with a message of the server, for a guest link
  and for a password reset
- The anti-robot check (ALTCHA) shows on the sign-in, forgotten password and sign-up forms when it
  is turned on: it was working, but nothing said so. The proof is solved as the form shows

### Changed

- A password is tried four times, then waits: the sign-in (one minute), the password of a share and
  the password of a file request (five minutes). The first refusal says the usual sentence ("Wrong
  email or password", "Wrong password"), the next ones how many tries remain, the last one how long
  to wait

### Fixed

- A wrong share password showed no message to a visitor (the page took it for a session that ran
  out), and was sent, and counted, twice for a signed-in member
- The daily maintenance failed every day once a file request had gone unanswered for a week: it
  deleted the guest account behind the link while the request still pointed at it (a foreign key),
  and stopped before clearing the expired cache entries. The request now lets go of its guest
  account first, and that account stays while the request is open: deleted after a week as before,
  a request of 14 or 30 days would have stopped opening on its eighth day
- Signing out leads to the sign-in page itself, not back to the page that was open
- The text of the search field is no longer cut at the bottom

### Security

- The hooks tusd calls answered from any private address, and `/api/tusd-hooks/` (a trailing slash)
  went past the block of Caddy: behind a reverse proxy, anyone could reach them. Only the loopback
  of the container may now, whatever the spelling of the path
- OpenID Connect and Keycloak sign-ins use PKCE (S256): a caught authorisation code cannot be
  exchanged elsewhere, and a Keycloak client that requires PKCE accepts the sign-in
- The files tusd kept for uploads nobody finished (never encrypted) are deleted after a week, like
  their sessions
- Minor updates of PHP and front-end dependencies (jwt-auth 2.10.0, Sanctum 4.4.0, Vue 3.5.45,
  Vite 6.4.6…); no advisory was open

## [1.3.0] — 2026-11-16

### Changed

- The guest of a file request gets a page like the download page: who asks, how many files, the
  largest size, the time left, the message, then the drop zone. No menu and no access options: the
  guest only drops files
- The member who asks sets the rules: how long the files received stay online (within the
  administration's limit; the default lifetime of a share otherwise), and an optional password.
  A guest can no longer set a lifetime or a password on the files they drop

### Added

- A file request can be protected by a password, passed on apart from the link. The link alone no
  longer opens it, a wrong password does not use it up, and guesses are slowed down (ten every five
  minutes). The password is kept hashed: asking once more for a protected request brings it back to
  the form, to type the password again

### Security

- Laravel 13.42.0: the output of Concurrency tasks could be forged (GHSA-63hm-g96r-2m2q, low;
  OpenTransfer does not use Concurrency)

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

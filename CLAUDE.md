# OpenTransfer

Fork maintenu d'Erugo (abandonné en amont) : partage de fichiers auto-hébergé, Laravel 13 / PHP 8.5,
front Vue 3 + Vite, uploads via tusd, image Docker Alpine publiée sur `ghcr.io/juzosuzuya20/opentransfer`.
Utilisé en production par une entreprise : la stabilité du SSO et des partages prime sur la nouveauté.

## Commandes

- Tests : `php artisan test` (SQLite en mémoire, voir `phpunit.xml`)
- Audits : `composer audit` et `npm audit --audit-level=high`
- Build du front : `npm ci && npx vite build`
- Image : `docker build -f docker/alpine/Dockerfile .` (la CI fait build + test de fumée + publication)
- Artisan dans un conteneur : `docker exec <conteneur> ot-artisan …` (`php artisan` n'y a pas l'environnement de l'application)

## Conventions

- Messages de commit en français, style Conventional Commits (`fix(auth): …`, `fix(deps): …`, `build: …`).
- Le code, les commentaires de code et les messages d'API restent en anglais, comme le code d'origine.
- Tout correctif de sécurité arrive avec un test qui échoue sans lui.
- `main` est déployée automatiquement : rien n'y arrive sans CI verte.

## Points sensibles (ne pas casser)

- URL de callback SSO `/auth/provider/{uuid}/callback` : déclarée chez les fournisseurs d'identité.
- Logique de correspondance SSO (sub lié, `trust_email`, `allow_registration`) : couverte par
  `tests/Feature/ExternalAuthTest.php`.
- `TRUSTED_PROXIES` : seul `X-Forwarded-For` est cru, jamais Host ni Proto (ils construisent l'URL de callback SSO).
  Caddy doit croire les mêmes proxies que Laravel (`start-container`), sinon il réécrit l'en-tête.
- Données existantes : le stockage et la base d'une instance Erugo doivent rester utilisables tels quels ;
  une migration ne doit jamais perdre de partages ni d'utilisateurs.
- `socialiteproviders` / Socialite : fixé sur `^5.30` tant que `jumbojett/openid-connect-php` exige phpseclib 3.
- tusd : version et empreintes SHA-256 épinglées dans les deux Dockerfiles (`docker/alpine`, `docker/dev`).
- Les actions GitHub sont épinglées par SHA de commit, avec la version en commentaire.

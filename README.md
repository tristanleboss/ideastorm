# IdeaStorm

A Symfony application for collecting ideas about projects — in writing, in your own voice, on
camera, or as whatever file you happen to have.

Visitors can browse every project, see who is assigned to it and in what role, and read the
ideas and discussions without an account. Creating one — with an email and password, or through
Google, GitHub or Facebook — unlocks submitting, commenting and voting.

The UI is Vue 3 loaded from a CDN, driven entirely by AJAX endpoints on the Symfony side. There
is no build step, no bundler and no `node_modules`: clone, install the PHP dependencies, and run.

## What it does

**Projects and people**
- A directory of projects, searchable, each with a summary, description, status and accent colour.
- Each project lists its team grouped by role — owner, project lead, contributor, reviewer,
  observer — with an optional note about what each person is focused on.
- Owners and leads assign and re-assign members. A project can never be left without an owner.
- Anyone with an account can start a project and becomes its owner.

**Ideas**
- An idea is a title plus any combination of text, voice notes, video, images and documents.
- **Sub-ideas**: any idea can be branched into *versions* — the same feature, a different take.
  Versions live under their parent, carry their own label, votes and discussion, and are never
  counted as separate ideas. Branching stops at one level on purpose: versions of versions turn
  a conversation into a maze.
- A status pipeline (proposed → under review → accepted / not moving forward / shipped) that
  owners, leads and reviewers drive.
- The board filters by status, searches full text, and sorts by newest, votes, discussion or
  number of versions.

**Capturing an idea**
- **Voice notes** recorded in the browser, WhatsApp style, with a live level meter and a timer.
- **Video** recorded straight from the device camera, with a live preview and a front/back
  camera switch on phones.
- **Camera and gallery** via the native picker on mobile.
- **Drag and drop** anywhere onto the composer, and **paste** an image straight from the clipboard.
- Everything is previewed locally before it is sent, and posted in a single request — so an idea
  is never stored half-finished.

**Discussion and voting**
- A comment thread per idea, with one level of replies, editable and deletable by their author.
  Project owners and leads can moderate a comment away but cannot rewrite what somebody said.
- Up/down voting, one vote per account, clicking the same arrow again takes it back.

## Requirements

- PHP 8.2 or newer with the `pdo_sqlite`, `intl`, `fileinfo`, `mbstring` and `gd` extensions
- Composer

Nothing else. The default database is a SQLite file, so there is no server to install.

## Getting started

```bash
composer install

# Create the database and load the demo data.
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console doctrine:fixtures:load --no-interaction

# Run it.
composer dev
```

Then open <http://127.0.0.1:8000>.

The fixtures create three projects and six accounts. They all share the password `ideastorm`:

| Email | Role in the demo |
| --- | --- |
| `ada@example.com` | Owner of Sea Breeze, and an admin |
| `ben@example.com` | Project lead |
| `chidi@example.com` | Contributor |
| `dee@example.com` | Reviewer |
| `eli@example.com` | Lead of the tool library |
| `fay@example.com` | Owner of the tool library |

### Running it another way

`composer dev` is a thin wrapper around PHP's built-in server:

```bash
php -S 127.0.0.1:8000 -t public dev-router.php
```

The `dev-router.php` shim exists because the built-in server otherwise hands `.css` and `.js`
requests to `public/index.php`, which tries to execute them as PHP. If you use the
[Symfony CLI](https://symfony.com/download) (`symfony server:start`) or a real web server
pointed at `public/`, you do not need it.

> **Recording needs https.** Browsers only hand out the microphone and camera on a secure
> origin. `localhost` counts as secure, so recording works in local development; on any other
> host, serve the site over https or the recorder will say so and fall back to file attachments.

## Configuration

Everything lives in `.env`; override it in `.env.local`, which is not committed.

| Variable | Default | What it does |
| --- | --- | --- |
| `DATABASE_URL` | a SQLite file in `var/` | Swap in MySQL or PostgreSQL — commented examples are in `.env` |
| `IDEASTORM_UPLOAD_DIR` | `var/uploads` | Where attachment bytes are stored, outside the document root |
| `IDEASTORM_MAX_UPLOAD_SIZE` | `67108864` (64 MB) | Per-file limit, enforced on both sides |
| `OAUTH_GOOGLE_ID` / `_SECRET` | empty | Google sign-in |
| `OAUTH_GITHUB_ID` / `_SECRET` | empty | GitHub sign-in |
| `OAUTH_FACEBOOK_ID` / `_SECRET` | empty | Facebook sign-in |

### Social logins

A provider stays invisible until both of its variables are filled in: the login page only offers
what is configured, and starting a flow for an unconfigured provider says so rather than
redirecting to a broken consent screen. Register these redirect URIs with each provider:

```
https://your-host/connect/google/check
https://your-host/connect/github/check
https://your-host/connect/facebook/check
```

Signing in with a provider matches on the verified email address first, so somebody who
registered with a password and later signs in with Google lands on the same account rather than
a duplicate. A provider that shares no email (a GitHub account with no verified address, say)
still gets an account, parked on a non-routable placeholder address.

Also raise PHP's own `upload_max_filesize` and `post_max_size` if you increase
`IDEASTORM_MAX_UPLOAD_SIZE` — PHP rejects an oversized request before Symfony ever sees it.

## How it fits together

```
src/
  Controller/
    HomeController.php          three routes, one Vue shell, so links can be pasted
    SecurityController.php      login, registration, the social sign-in entry points
    AttachmentController.php    streams upload bytes back with a safe disposition
    Api/                        the AJAX endpoints the frontend talks to
  Entity/                       User, Project, ProjectMembership, Idea, Attachment, Comment, Vote
  Enum/                         ProjectRole, ProjectStatus, IdeaStatus, AttachmentKind
  Security/
    SocialAuthenticator.php     completes an OAuth callback
    SocialAccountLinker.php     finds or creates the local account behind a social profile
    Voter/                      who may edit, delete, triage, branch, comment and vote
  Service/
    AttachmentStorage.php       validates and stores uploads outside the document root
    ApiPresenter.php            shapes entities into the JSON the frontend consumes
  EventSubscriber/              CSRF guard and JSON error responses for /api
public/
  css/app.css                   one stylesheet, light and dark
  js/api.js                     API client, shared store, formatting helpers
  js/components/                Vue components, plain scripts in dependency order
  js/app.js                     boot and the small history-API router
  js/vendor/                    a pinned Vue build, used if the CDN cannot be reached
```

### The API

Reads are public; writes need an account and a CSRF header. Errors always come back as JSON —
`{"error": "...", "message": "..."}` — with field-level detail on a validation failure.

| Method | Path | |
| --- | --- | --- |
| `GET` | `/api/session` | Current user, CSRF token, enum vocabulary, upload limits |
| `GET` `POST` | `/api/projects` | List (with `?q=`) and create |
| `GET` `PATCH` | `/api/projects/{slug}` | Detail and update |
| `GET` `POST` | `/api/projects/{slug}/members` | Team, and assigning somebody |
| `PATCH` `DELETE` | `/api/projects/{slug}/members/{id}` | Change a role, remove an assignment |
| `GET` `POST` | `/api/projects/{slug}/ideas` | The board (`?status=` `?sort=` `?q=`) and submission |
| `GET` `PATCH` `DELETE` | `/api/ideas/{id}` | One idea |
| `POST` | `/api/ideas/{id}/status` | Triage |
| `POST` | `/api/ideas/{id}/vote` | `{"value": 1}`, `{"value": -1}`, or the same value again to retract |
| `POST` | `/api/ideas/{id}/attachments` | Add files to an existing idea |
| `DELETE` | `/api/attachments/{id}` | Remove one |
| `GET` `POST` | `/api/ideas/{id}/comments` | The thread, and posting |
| `PATCH` `DELETE` | `/api/comments/{id}` | Edit or remove a comment |
| `GET` | `/api/users?q=` | Account lookup for the member picker (signed in only) |

Idea submission is the one endpoint that takes `multipart/form-data`: `files[]` carries the
attachments and `durations[]` the length in seconds of each recording, aligned by index.

## Notes on the handling of uploads

- Bytes are written **outside the document root** under a random name, and served back through
  `AttachmentController`. The web server can never execute an upload.
- The MIME type is read **from the file's contents** with `fileinfo`, not from what the browser
  claimed, and checked against an allowlist. A PHP script renamed `photo.png` is rejected.
- Audio, video and images are sent back `inline`; everything else is sent as a download, with
  `X-Content-Type-Options: nosniff` on both.
- If any file in a submission is rejected, the whole idea is discarded — files included — rather
  than leaving an idea whose attachments silently went missing.

## Tests

```bash
php bin/phpunit
```

80 tests covering the enums and the upload rules directly, and the API through real HTTP
requests: permissions per role, versioning, the upload path end to end, voting arithmetic,
comment threading, CSRF, registration and social-account linking.

## Licence

MIT.

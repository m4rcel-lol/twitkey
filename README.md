# Twitkey

Twitkey is a self-hosted PHP 8.2 microblogging app styled after Twitter's 2009 web UI, with modern additions for community notes, verified account badges, business affiliations, direct messages, notifications, and an admin panel.

## Quick Start

```sh
cp .env.example .env
docker compose up -d --build
```

Then open `http://localhost`.

## First Admin

1. Register a normal user at `http://localhost/register`.
2. Promote that user once:

```text
http://localhost/admin/setup?token=changeme123&username=yourusername
```

Set `ADMIN_SETUP_TOKEN` before deployment. After the first successful promotion, Twitkey writes `/data/.admin_setup_done` and the setup route returns 404.

Docker Compose reads `.env` in two ways here: it uses `HTTP_PORT` and `HTTPS_PORT` for host port mapping, and `env_file: .env` passes the application settings into the PHP container.

## Environment Variables

| Variable | Default | Description |
| --- | --- | --- |
| `APP_NAME` | `Twitkey` | Application name in the UI. |
| `APP_URL` | `http://localhost` | Public site URL (scheme + host + port if non-default). **Required for passkeys/WebAuthn to work reliably**; set to the exact URL users visit (e.g. `https://twitkey.example.com` or `http://localhost:8080`). Used as canonical origin for security checks. |
| `APP_DEBUG` | `false` | Enables PHP error display when `true`. |
| `HTTP_PORT` | `80` | Host port mapped to container port 80. |
| `HTTPS_PORT` | `443` | Host port mapped to container port 443. |
| `DB_DRIVER` | `sqlite` | `sqlite` or `mysql`. |
| `DB_PATH` | `/data/twitkey.db` | SQLite database path. |
| `DB_HOST` | `mysql` | MySQL host. |
| `DB_PORT` | `3306` | MySQL port. |
| `DB_NAME` | `twitkey` | MySQL database. |
| `DB_USER` | `twitkey` | MySQL username. |
| `DB_PASS` | `changeme` | MySQL password. |
| `ADMIN_SETUP_TOKEN` | unset | One-time first-admin setup token. |
| `MAX_AVATAR_SIZE_KB` | `2048` | Maximum uploaded avatar size. |
| `MAX_ATTACHMENT_SIZE_KB` | `51200` | Maximum tweet attachment size. |
| `KLIPY_API_KEY` | unset | Server-side Klipy API key for legacy GIF API support. Do not expose it in frontend code. |
| `GIF_API_SEARCH_URL` | `https://api.klipy.com/v2/search?q={query}&key={key}&limit=12&media_filter=gif,tinygif,mediumgif,nanogif,preview&contentfilter=low` | Legacy Klipy GIF search endpoint. `{query}` and `{key}` are filled server-side if older clients call the API. |
| `LOCATION_SEARCH_URL` | `https://nominatim.openstreetmap.org/search?format=json&limit=6&q={query}` | Location search endpoint. Replace `{query}` with the encoded search term. Respect the provider usage policy or swap in your own endpoint. |

## Features

- 140-character tweets, replies, classic RT retweets, favorites, follows, @replies, search, trends, profile pages, public and home timelines.
- Polls, image/audio/video attachments, pasted image uploads, map-picked locations, and scheduled posts from the classic compose box.
- Near-realtime polling for new posts, direct messages, notification counts, and poll result updates.
- Direct messages with unread badges, sent-message counters, notifications, pagination, avatar uploads, and profile settings.
- Private accounts, approved follow requests, follower-only posts, and message privacy controls.
- “Follows you” indicators beside user names when applicable.
- Admin-managed site alert banner with live client refresh.
- Community Notes with eligibility, helpful/unhelpful voting, automatic approval/rejection, admin moderation, and misleading-note flags.
- Admin dashboard with user moderation, tweet moderation, note moderation, verification grants, suspension reasons, account deletion, and audit logging.
- Help, Privacy Policy, and Terms of Service pages plus a global footer.
- Safe rich embeds for known providers, generic link cards for other URLs, and click-to-expand image/GIF/video media previews.
- Verified Business and Verified Government badges rendered through the shared badge helper.
- Business affiliation invites, acceptance/decline, revocation, one-business-at-a-time enforcement, and mini-avatar badges wherever names render.
- SQLite first-run bootstrap with schema and index creation. MySQL can be selected with `DB_DRIVER=mysql`.
- CSRF protection, bcrypt passwords, session hardening, prepared PDO queries, server-side escaping, security headers, server-side tweet length validation, rate limiting, and safe GD avatar resizing.

## Development Checks

```sh
find . -path ./.git -prune -o -name '*.php' -print -exec php -l {} \;
php -S 127.0.0.1:8080 -t public
```

For Docker verification:

```sh
docker compose up -d --build
docker compose logs -f twitkey
```

## Screenshots

Add screenshots here after deploying locally:

- Home timeline
- Profile page with badges
- Tweet detail with Community Note
- Admin dashboard

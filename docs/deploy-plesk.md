# Deploying Umami on Plesk (Docker Compose)

Target setup: one self-hosted Umami v3 instance per Plesk server, reachable via
a dedicated subdomain — e.g. `a.moritz-graf.de` for the filament-cms sites and
`a.kuckuck.cam` for the kuckuck.cam frontends. If both subdomains live on the
**same** Plesk server, deploy **one** stack and point both subdomains at the
same container (Umami ignores the Host header); otherwise repeat these steps
per server.

Umami v3 is PostgreSQL-only. Timestamps: keep the database in UTC (default in
the official image).

## 1. Prerequisites

- Plesk Obsidian with the **Docker** extension installed (it ships the local
  Docker daemon; the extension UI can also deploy Compose files) — or plain SSH
  root access with the `docker compose` plugin.
- A DNS record for the subdomain (`a.example.com` → server IP) and the
  subdomain created in Plesk (*Websites & Domains → Add Subdomain*, empty
  docroot is fine).

## 2. Compose stack

As root via SSH:

```bash
mkdir -p /opt/umami && cd /opt/umami
openssl rand -hex 32   # use the output as APP_SECRET below
```

`/opt/umami/docker-compose.yml` (based on the official file; the host port is
bound to loopback so only nginx can reach it):

```yaml
services:
  umami:
    image: ghcr.io/umami-software/umami:latest
    ports:
      - "127.0.0.1:3001:3000"
    environment:
      DATABASE_URL: postgresql://umami:CHANGE-DB-PASSWORD@db:5432/umami
      APP_SECRET: CHANGE-ME-openssl-rand-hex-32
      # Optional hardening / tweaks:
      # TRACKER_SCRIPT_NAME: insights.js   # rename script.js (see UMAMI_TRACKER_SCRIPT in the apps)
      # DISABLE_TELEMETRY: "1"
    depends_on:
      db:
        condition: service_healthy
    init: true
    restart: always
    healthcheck:
      test: ["CMD-SHELL", "curl http://localhost:3000/api/heartbeat"]
      interval: 5s
      timeout: 5s
      retries: 5
  db:
    image: postgres:15-alpine
    environment:
      POSTGRES_DB: umami
      POSTGRES_USER: umami
      POSTGRES_PASSWORD: CHANGE-DB-PASSWORD
    volumes:
      - umami-db-data:/var/lib/postgresql/data
    restart: always
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U $${POSTGRES_USER} -d $${POSTGRES_DB}"]
      interval: 5s
      timeout: 5s
      retries: 5
volumes:
  umami-db-data:
```

```bash
docker compose up -d
curl -s http://127.0.0.1:3001/api/heartbeat   # "ok" once migrations finished
```

Prefer pinning the image (e.g. `ghcr.io/umami-software/umami:3.2.0`) over
`latest` and bump it deliberately.

## 3. Subdomain → container proxy

Robust variant (recommended by Plesk staff, works regardless of how the stack
was started): *Websites & Domains → a.example.com → Apache & nginx Settings →
Additional nginx directives*:

```nginx
location / {
    proxy_pass http://127.0.0.1:3001;
    proxy_set_header Host              $host;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

Alternative: the extension's *Docker Proxy Rules* UI on the subdomain — that
requires the container to be managed by the Docker extension with a manual
port mapping, and has known quirks with sub-path routing; the manual
directives above avoid all of that.

SSL: *Websites & Domains → a.example.com → SSL/TLS Certificates* → issue a
free Let's Encrypt certificate (SSL It!), enable the HTTP→HTTPS redirect.
TLS terminates at nginx; the container keeps speaking plain HTTP.

Same-server second hostname (e.g. `a.kuckuck.cam` next to `a.moritz-graf.de`):
create the second subdomain, paste the same nginx directives, issue its own
certificate — done, same container.

## 4. First login & API user

1. Open `https://a.example.com` — default login is **admin / umami**.
2. Change the admin password immediately (Profile → Change password).
3. Create a dedicated user for the apps (Admin → Users → Add user, e.g.
   `provisioner`): the filami provisioning jobs log in with this account and
   websites it creates are owned by it. Its credentials go into the app's
   `UMAMI_USERNAME` / `UMAMI_PASSWORD` env vars.

Note: self-hosted Umami has no API keys (Cloud-only feature) — auth is the
login token, which filami caches and refreshes automatically. Rotating the
password or `APP_SECRET` invalidates cached tokens; filami re-logs-in on the
next 401.

## 5. Wire up the apps

Per app (`.env`):

```dotenv
UMAMI_URL=https://a.example.com
UMAMI_USERNAME=provisioner
UMAMI_PASSWORD=...
```

Then backfill websites for tenants that already exist:

```bash
php artisan filami:sync
```

If the app previously tracked against a **different** Umami server, its stored
website ids are meaningless to the new instance and would be skipped forever
(the frontend keeps shipping a tracker whose events are dropped). `--push`
re-links them:

```bash
php artisan filami:sync --push
```

New tenants get their Umami website automatically from now on.

## 6. Updates & backup

Update (pin a new tag first if pinned):

```bash
cd /opt/umami && docker compose pull && docker compose up -d
```

Nightly dump via Plesk *Tools & Settings → Scheduled Tasks* (run as root):

```bash
docker compose -f /opt/umami/docker-compose.yml exec -T db pg_dump -U umami umami | gzip > /var/backups/umami-$(date +\%F).sql.gz
```

Keep `/var/backups` inside the Plesk backup scope (or rotate with `find
-mtime +14 -delete`). Restore = `gunzip -c dump.sql.gz | docker compose exec
-T db psql -U umami umami` into a fresh volume.

## 7. Session replay & heatmaps (optional)

Enable per website in Umami (Websites → Edit → *Replays & Heatmaps*), then
switch the site on in the app — filami adds the second recorder script. Replay
needs Umami 3.1+, heatmaps 3.2+.

Two operational caveats:

- **Disk.** The open-source build ships no retention job for recorded
  sessions, so `session_replay` grows unbounded. Watch the volume and prune
  yourself if you enable this on busy sites.
- **Heatmaps frame the tracked site.** The overlay is a live `<iframe>` of the
  real page. Any site answering `X-Frame-Options: SAMEORIGIN` shows a blank
  overlay — it has to allow framing by the Umami origin.

## 8. Notes

- The container port stays loopback-only (`127.0.0.1:3001`) — never expose it
  publicly; the Plesk firewall does not need an extra rule.
- Umami is cookie-less; visitors can opt out per browser via
  `localStorage.setItem('umami.disabled', 1)` (the kuckuck.cam frontends
  expose this at `/notme`).
- If trackers get ad-blocked, set `TRACKER_SCRIPT_NAME` server-side and the
  matching `UMAMI_TRACKER_SCRIPT` in the apps.
- Umami v3 dropped MySQL — PostgreSQL only (the stack above already is).

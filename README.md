# Watchtower Hub

Self-hosted monitoring hub for your homelab and dev infrastructure — watches Proxmox VE, Proxmox Backup Server (PBS), and arbitrary HTTP service endpoints, raises debounced alerts, pushes critical ones to your phone via [ntfy](https://ntfy.sh), and serves a read-only API to the Watchtower iOS app.

Built with Laravel 12, PostgreSQL, and FrankenPHP, shipped as a Docker image.

## What it does

- **Collects** state from your infrastructure on a schedule:
  - **Proxmox VE** — cluster resources (nodes, LXC, VMs, storage) auto-discovered from the `cluster/resources` API.
  - **Proxmox Backup Server** — datastore usage and backup freshness.
  - **HTTP service checks** — any URL you point it at (reachability + latency).
- **Records** a current status per target plus rolling metrics (CPU %, memory %, disk %, latency, backup age, …).
- **Evaluates** alert rules with debouncing: an alert moves `pending → firing → resolved`, only firing after its condition holds for the rule's `duration_seconds`, so transient blips don't page you.
- **Notifies** via ntfy — only Critical-tier alerts are pushed; warnings are recorded but stay quiet.
- **Serves** a read-only, token-authenticated JSON API (Laravel Sanctum) consumed by the Watchtower iOS app.

## Architecture

```
                 ┌─────────────┐
  Proxmox VE ───▶│             │
  PBS ──────────▶│  collectors │──▶ checks + metrics ──▶ alert engine ──┬──▶ ntfy push (critical)
  HTTP services ▶│             │     (Postgres)          (debounced      │
                 └─────────────┘                          rules)        └──▶ mobile API (Sanctum)
                                                                                   │
                                                                                   ▼
                                                                            Watchtower iOS app
```

- **Storage:** PostgreSQL.
- **Scheduler:** runs `collect:run` every 60 s and `alerts:evaluate` every 30 s.
- **Discovery:** Proxmox/PBS infra targets are auto-created on first collection. HTTP service checks are not auto-discoverable, so you add those yourself (see [Adding service checks](#adding-service-checks)).

## Quick start (Docker — no clone, no .env)

The published image and a single compose file are all you need:

```bash
# 1. Download the standalone compose file
curl -O https://raw.githubusercontent.com/xNiicki/watchtower-hub/main/deploy/docker-compose.yml

# 2. Launch (db, app, scheduler). The database is internal and APP_KEY auto-generates.
docker compose up -d

# 3. Set your admin password (one-time)
docker compose exec app php artisan watchtower:admin
```

Then open **`http://<host>:8000/admin`** and do everything in the UI:

1. Log in with the admin password you just set.
2. **Settings** → enter your Proxmox / PBS / ntfy connection details and hit **Test connection**.
3. **Tokens** → mint a mobile API token (shown once) and paste it into the iOS app to pair.
4. Your Proxmox guests/storage **auto-discover**; add any HTTP service checks under **Targets**; tune **Rules** as you like.

No `.env` editing required — all configuration lives in the admin UI (encrypted at rest).

> Developing from source instead? `cp .env.example .env`, set the values there, and use the repo's `compose.yaml` (which builds the image locally).

## Required credentials & least privilege

Create dedicated API tokens with the minimum role/privilege — the hub only ever reads.

| Service             | Token needs            | Notes                                              |
| ------------------- | ---------------------- | -------------------------------------------------- |
| Proxmox VE          | **PVEAuditor** role    | Read-only cluster/resource visibility.             |
| Proxmox Backup (PBS)| **DatastoreAudit**     | Read-only datastore/backup visibility. Optional.   |
| ntfy                | none, or a Bearer token| Optional. Token only needed for protected topics.  |

Set `*_VERIFY_TLS=true` once your Proxmox/PBS endpoints present trusted certificates.

## Mobile app

Watchtower Hub pairs with the **Watchtower iOS app** (a NativePHP app, maintained in a separate repository). The app consumes the hub's read-only `/api/v1` endpoints using the mobile token described above and can acknowledge alerts.

## Roadmap (not yet implemented)

- Syslog ingestion / log-based checks.
- An app-telemetry satellite for monitoring your own deployed apps.

These are planned but **not** part of the current release — the sections above describe only what is actually built.

## License

Watchtower Hub is licensed under the **GNU AGPL-3.0**. See [LICENSE](LICENSE).

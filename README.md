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

## Quick start (Docker)

```bash
# 1. Configure
cp .env.example .env

# 2. Set the app key
php artisan key:generate --show     # paste the output into APP_KEY in .env

# 3. Edit .env and fill in:
#    - DB_PASSWORD
#    - PROXMOX_BASE_URL / PROXMOX_TOKEN_ID / PROXMOX_TOKEN_SECRET
#    - PBS_BASE_URL / PBS_TOKEN_ID / PBS_TOKEN_SECRET   (optional)
#    - NTFY_BASE_URL / NTFY_TOPIC                        (optional)

# 4. Launch (db, app, scheduler)
docker compose up -d

# 5. Grab the mobile API token (printed once on first boot)
docker compose logs app | grep -A2 "MOBILE API TOKEN"
```

On first boot the hub provisions an operator account and mints a single mobile API token, printing it **once** to the container logs. Copy that token into the iOS app to pair it. The hub never serves a web login — auth is token-only.

### Adding service checks

Infra (Proxmox/PBS) auto-discovers, but HTTP service checks don't. The example seeder ships a couple of disabled templates you can edit and enable:

```bash
docker compose exec app php artisan db:seed --class=FleetSeeder   # example service-check templates (disabled)
docker compose exec app php artisan db:seed --class=RuleSeeder    # default alert rules
```

Edit `database/seeders/FleetSeeder.php` to point the example entries at your own URLs and set `enabled => true`, or add your own. See that file's docblock for details.

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

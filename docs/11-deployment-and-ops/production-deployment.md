# Production Deployment Runbook

Deploy Family App backend for a **private beta** or early production launch. Assumes Ubuntu 22.04/24.04 LTS (or similar) with root/sudo access.

For local development, see [commands.md](./commands.md). For env vars, see [env-variables.md](./env-variables.md).

---

## Target architecture

```
                    Internet
                        │
                   ┌────▼────┐
                   │  nginx  │  :443 (API)  :443/ws (Reverb proxy)
                   └────┬────┘
          ┌──────────────┼──────────────┐
          ▼              ▼              ▼
    php-fpm (API)   Reverb :8080   queue workers
          │              │              │
          └──────────────┼──────────────┘
                         ▼
                    MySQL 8.x
                         │
                    S3 (media ciphertext)
                         │
                    FCM (push alerts)
```

**Minimum processes (Supervisor):**

| Program | Command | Purpose |
|---------|---------|---------|
| `familyapp-api` | php-fpm (via systemd) | REST API |
| `familyapp-reverb` | `php artisan reverb:start` | WebSockets |
| `familyapp-queue` | `php artisan queue:work` | FCM push, async jobs |

---

## 1. Server preparation

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx mysql-server php8.3-fpm php8.3-cli \
  php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip \
  php8.3-bcmath php8.3-intl supervisor git unzip
```

Install Composer globally if needed: https://getcomposer.org

Create app user and directory:

```bash
sudo useradd -m -s /bin/bash familyapp
sudo mkdir -p /var/www/familyapp
sudo chown familyapp:familyapp /var/www/familyapp
```

---

## 2. Deploy application code

```bash
sudo -u familyapp -H bash -c '
  cd /var/www/familyapp
  git clone <your-repo-url> .
  cd backend
  composer install --no-dev --optimize-autoloader
  cp .env.example .env
  php artisan key:generate
'
```

### Production `.env` essentials

```env
APP_NAME=FamilyApp
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.yourdomain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=family_app
DB_USERNAME=family_app
DB_PASSWORD=<strong-password>

CACHE_STORE=database
QUEUE_CONNECTION=database
SESSION_DRIVER=database

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=family-app
REVERB_APP_KEY=<generate-long-random-key>
REVERB_APP_SECRET=<generate-long-random-secret>
REVERB_HOST=api.yourdomain.com
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080

MEDIA_DISK=s3
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=family-app-media
# No AWS_ENDPOINT in production (real S3)

FIREBASE_PROJECT_ID=...
FIREBASE_CLIENT_EMAIL=...
FIREBASE_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----\n"
```

Run migrations:

```bash
cd /var/www/familyapp/backend
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Set permissions:

```bash
sudo chown -R www-data:familyapp /var/www/familyapp/backend/storage
sudo chown -R www-data:familyapp /var/www/familyapp/backend/bootstrap/cache
sudo chmod -R 775 /var/www/familyapp/backend/storage
sudo chmod -R 775 /var/www/familyapp/backend/bootstrap/cache
```

---

## 3. nginx — API + WebSocket proxy

Create `/etc/nginx/sites-available/familyapp`:

```nginx
upstream php-fpm {
    server unix:/run/php/php8.3-fpm.sock;
}

# Redirect HTTP → HTTPS
server {
    listen 80;
    server_name api.yourdomain.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name api.yourdomain.com;

    ssl_certificate     /etc/letsencrypt/live/api.yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.yourdomain.com/privkey.pem;

    root /var/www/familyapp/backend/public;
    index index.php;

    # 0 = no body-size limit (S3-backed media uses chunked uploads).
    # If you prefer a hard ceiling, use e.g. 1024M — never leave nginx at the
    # default 1m or gallery multi-upload returns HTTP 413.
    client_max_body_size 0;

    # Also set PHP: upload_max_filesize=1024M and post_max_size=1024M.

    # Laravel API
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass php-fpm;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 120;
    }

    # Reverb WebSocket (Pusher-compatible path)
    location /app/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 86400;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Enable site and obtain TLS (Certbot):

```bash
sudo ln -s /etc/nginx/sites-available/familyapp /etc/nginx/sites-enabled/
sudo certbot --nginx -d api.yourdomain.com
sudo nginx -t && sudo systemctl reload nginx
```

Mobile app must use:

```bash
flutter run --dart-define=API_BASE_URL=https://api.yourdomain.com/api/v1
```

Reverb client reads `REVERB_HOST`, `REVERB_PORT=443`, `REVERB_SCHEME=https` from `GET /groups/realtime/config`.

---

## 4. Supervisor — Reverb, queue, and scheduler

Create `/etc/supervisor/conf.d/familyapp.conf`:

```ini
[program:familyapp-reverb]
process_name=%(program_name)s
command=php /var/www/familyapp/backend/artisan reverb:start
directory=/var/www/familyapp/backend
autostart=true
autorestart=true
user=familyapp
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/familyapp/reverb.log
stopwaitsecs=10

[program:familyapp-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/familyapp/backend/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
directory=/var/www/familyapp/backend
autostart=true
autorestart=true
user=familyapp
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/familyapp/queue.log
stopwaitsecs=3600

[program:familyapp-schedule]
process_name=%(program_name)s
command=php /var/www/familyapp/backend/artisan schedule:work
directory=/var/www/familyapp/backend
autostart=true
autorestart=true
user=familyapp
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/familyapp/schedule.log
stopwaitsecs=10
```

`schedule:work` runs Laravel’s task runner continuously. Calendar notifications (`calendar:send-notifications`) fire once per day at **12:00 AM** server time — no separate system cron entry is required when this program is running.

**Alternative (cron instead of Supervisor):** add one line to the `familyapp` user crontab:

```cron
* * * * * cd /var/www/familyapp/backend && php artisan schedule:run >> /dev/null 2>&1
```

Use either Supervisor **or** cron, not both.

```bash
sudo mkdir -p /var/log/familyapp
sudo chown familyapp:familyapp /var/log/familyapp
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

---

## 5. php-fpm tuning (small VPS)

Edit `/etc/php/8.3/fpm/pool.d/www.conf`:

```ini
pm = dynamic
pm.max_children = 20
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 8
pm.max_requests = 500
```

```bash
sudo systemctl restart php8.3-fpm
```

---

## 6. Health checks & monitoring

### API health

```bash
curl -s https://api.yourdomain.com/api/v1/health | jq
```

Expected (200):

```json
{
  "status": "ok",
  "app": "FamilyApp",
  "version": "v1",
  "timestamp": "...",
  "checks": { "database": "ok" }
}
```

Returns **503** if database is unreachable (`status: degraded`).

### Process checks

```bash
sudo supervisorctl status familyapp-reverb familyapp-queue:*
curl -I https://api.yourdomain.com/api/v1/health
```

### Logs

| Log | Path |
|-----|------|
| Laravel | `/var/www/familyapp/backend/storage/logs/laravel.log` |
| Reverb | `/var/log/familyapp/reverb.log` |
| Queue | `/var/log/familyapp/queue.log` |
| nginx | `/var/log/nginx/error.log` |

---

## 7. Deploy updates (zero-downtime friendly)

```bash
cd /var/www/familyapp
sudo -u familyapp git pull

cd backend
sudo -u familyapp composer install --no-dev --optimize-autoloader
sudo -u familyapp php artisan migrate --force
sudo -u familyapp php artisan config:cache
sudo -u familyapp php artisan route:cache

sudo supervisorctl restart familyapp-queue:*
sudo supervisorctl restart familyapp-reverb
sudo systemctl reload php8.3-fpm
```

---

## 8. Capacity guide (order of magnitude)

Assumptions: family app, ~5–20 msgs/user/day, ~30% DAU, avg group size 5.

Full baseline (flow rankings, code/DB ratings, improvement backlog):
[implementation-capacity-report.md](../00-overview/implementation-capacity-report.md).

### Single VPS (2 vCPU / 4 GB)

| Metric | Comfortable range |
|--------|-------------------|
| Registered users | 300 – 1,000 |
| Daily active users | 50 – 200 |
| Concurrent WebSockets | 200 – 800 |
| Sustained message sends | 5 – 20 / sec |

**First bottlenecks:** MySQL CPU (unread counts, DB queue), Reverb memory.

### Modest cloud (2× app, RDS MySQL, Redis, dedicated Reverb, S3)

| Metric | Comfortable range |
|--------|-------------------|
| Registered users | 5,000 – 20,000 |
| Concurrent WebSockets | 2,000 – 8,000 |

Requires: `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `REVERB_SCALING_ENABLED=true`, unread-query optimization. See [v2 migration runbook](../14-v2-adapters/migration-runbook.md).

---

## 9. Pre-launch checklist

- [ ] `APP_DEBUG=false`, strong `APP_KEY`, TLS on all endpoints
- [ ] S3 bucket private; no public ACL on media
- [ ] Firebase service account configured; FCM tested on real device
- [ ] Reverb + queue + scheduler running under Supervisor
- [ ] Health check returns 200 with `database: ok`
- [ ] Mobile `API_BASE_URL` points to production HTTPS
- [ ] Database backups scheduled (daily mysqldump or managed RDS snapshots)
- [ ] Rate limiting on auth routes (recommended before public launch)

---

## Related docs

- [commands.md](./commands.md) — development workflow
- [env-variables.md](./env-variables.md) — full env reference
- [push-notifications-setup.md](../10-flutter-mobile/push-notifications-setup.md)
- [realtime WebSockets](../09-realtime-websockets/README.md)
- [client chat flow](../05-groups-and-chat/client-flow.md)

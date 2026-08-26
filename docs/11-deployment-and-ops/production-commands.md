# Production commands

One file. Run these on the production server. Paths assume `/var/www/familyapp/backend` — change if yours differs.

Add new production commands to this file only.

---

## Always running (Supervisor)

```bash
php artisan reverb:start
php artisan queue:work database --sleep=3 --tries=3 --max-time=3600
php artisan schedule:work
```

```bash
sudo supervisorctl status
sudo supervisorctl restart familyapp-reverb
sudo supervisorctl restart familyapp-queue:*
sudo supervisorctl restart familyapp-schedule
```

---

## Every deploy

```bash
cd /var/www/familyapp/backend
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo supervisorctl restart familyapp-queue:*
sudo supervisorctl restart familyapp-reverb
sudo supervisorctl restart familyapp-schedule
```

---

## Scheduled (via `schedule:work`)

```bash
php artisan calendar:send-notifications          # daily 00:00
php artisan storage:renew-plans                  # daily 00:15 (plan ends_at + monthly access roll)
php artisan media:sweep-pending-uploads          # hourly
php artisan media:sweep-pending-uploads --hours=24
```

Admin: reset a user's monthly access usage:

```bash
# Via API (admin Bearer token):
# POST /api/v1/admin/users/{uuid}/access-usage/reset
```


---

## First-time setup

```bash
cd /var/www/familyapp/backend
cp .env.example .env
php artisan key:generate
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

---

## Queue

```bash
php artisan queue:failed
php artisan queue:retry all
php artisan queue:flush
```

---

## Cache / debug

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan about
php artisan route:list --path=api/v1
```

---

## Health

```bash
curl -sS https://api.yourdomain.com/api/v1/health
sudo supervisorctl status
```

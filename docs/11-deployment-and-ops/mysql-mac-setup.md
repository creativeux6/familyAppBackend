# MySQL on Mac — Setup & GUI Clients

## Installed on your Mac

MySQL was installed via Homebrew and is running as a background service.

| Setting | Value |
|---------|-------|
| Host | `127.0.0.1` |
| Port | `3306` |
| Database | `family_app` |
| Username | `family_app` |
| Password | `secret` |
| Root (no password) | `mysql -u root` |

### Useful commands

```bash
# Start / stop MySQL
brew services start mysql
brew services stop mysql

# Connect via terminal
mysql -u family_app -psecret family_app

# Secure root account (recommended)
mysql_secure_installation
```

Laravel `.env` is already configured for this database.

---

## Best MySQL GUI clients for Mac

### Recommended: **TablePlus** (best overall Mac experience)

- Native Mac app — fast, clean, polished
- Supports MySQL, PostgreSQL, Redis, SQLite, etc.
- Easy connection setup, good table/data browsing
- **Free tier:** limited tabs/connections; paid ~$89 one-time
- Download: https://tableplus.com

**Best for:** daily development when you want the nicest Mac-native UI.

---

### Best free option: **Sequel Ace**

- Free and open source
- Built specifically for MySQL on Mac (successor to Sequel Pro)
- Lightweight, simple, reliable
- Download: Mac App Store or https://sequel-ace.com

**Best for:** MySQL-only work, zero cost, Mac-native feel.

---

### Official tool: **MySQL Workbench**

- Made by Oracle/MySQL team
- Full schema design, ER diagrams, query builder, admin
- Heavier and less Mac-native than TablePlus/Sequel Ace
- Free
- Download: https://dev.mysql.com/downloads/workbench/

**Best for:** complex schema design, ER diagrams, deep MySQL admin tasks.

---

### Cross-platform free: **DBeaver Community**

- Free, powerful, supports many databases
- Heavier UI (Java-based), not as Mac-native
- Download: https://dbeaver.io

**Best for:** if you also use PostgreSQL, Neo4j, etc. in one tool.

---

### Modern alternative: **Beekeeper Studio**

- Clean modern UI, open source
- MySQL + PostgreSQL + SQLite + others
- Download: https://www.beekeeperstudio.io

---

## Quick comparison

| Client | Price | Mac feel | MySQL focus | Best use |
|--------|-------|----------|-------------|----------|
| **TablePlus** | Free tier / paid | Excellent | Yes | Daily dev (recommended) |
| **Sequel Ace** | Free | Excellent | MySQL only | Free daily dev |
| **MySQL Workbench** | Free | Average | Yes | Schema/ER design |
| **DBeaver** | Free | Average | Yes | Multi-DB teams |
| **Beekeeper Studio** | Free / paid | Good | Yes | Modern open-source UI |

---

## Connection settings (any GUI)

Use these when adding a new connection:

```
Name:     Family App Local
Host:     127.0.0.1
Port:     3306
User:     family_app
Password: secret
Database: family_app
```

For root access (admin tasks):

```
User:     root
Password: (empty — run mysql_secure_installation to set one)
```

---

## Our recommendation for this project

1. **TablePlus** — installed via `scripts/install-tableplus.sh` (primary GUI for this project)
2. Open saved connection: `bash scripts/open-tableplus-family-db.sh`

### Install TablePlus (one-time)

```bash
cd /Users/codesorbit/www/familyApp
bash scripts/install-tableplus.sh
```

This installs TablePlus to `/Applications/TablePlus.app` (global — launch from Spotlight, Dock, or `open -a TablePlus`).

### Open Family App database in TablePlus

```bash
bash scripts/open-tableplus-family-db.sh
```

Or add connection manually in TablePlus:

| Field | Value |
|-------|-------|
| Name | Family App Local |
| Type | MySQL |
| Host | 127.0.0.1 |
| Port | 3306 |
| User | family_app |
| Password | secret |
| Database | family_app |

Connection reference: `tableplus-connection.json` in this folder.

---

## Other clients (alternatives)

## Terminal alternative (no GUI)

```bash
mysql -u family_app -psecret family_app

# List tables
SHOW TABLES;

# Example query
SELECT * FROM users;
```

Or use Laravel Tinker:

```bash
cd backend && php artisan tinker
>>> \App\Models\User::count();
```

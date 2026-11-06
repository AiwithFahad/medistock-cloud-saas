# MediStock Cloud — Deployment Guide

## Architecture (honest note)

MediStock Cloud's application is **Core PHP 8 + MySQL**. Vercel does not
provide native conventional PHP hosting, so the production architecture is:

| Layer            | Host                                                              |
|------------------|-------------------------------------------------------------------|
| PHP application  | Any PHP 8 + MySQL host (shared PHP hosting, VPS, Laravel Forge-style server, Plesk/cPanel, Docker) |
| Database         | MySQL 5.7+ / MariaDB 10.3+ (InnoDB)                               |
| Static demo      | The bundled `vercel-demo/` folder deploys to **Vercel** as the public showcase |

Do not claim the PHP app itself runs on Vercel unless you have verified a
compatible PHP runtime with the required extensions (PDO MySQL, mbstring,
JSON) end-to-end. The `vercel-demo/` static site is the Vercel deliverable.

## 1. Requirements
- PHP ≥ 8.0 with `pdo_mysql`, `mbstring`, `json`, `fileinfo`
- MySQL 5.7+ / MariaDB 10.3+, InnoDB
- Web server (Apache with mod_rewrite or Nginx + PHP-FPM) with the document
  root pointed at `public/`

## 2. Install
```bash
# 1. Create the database
mysql -u root -p -e "CREATE DATABASE medistock_cloud CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Configure environment
cp .env.example .env   # fill DB credentials + APP_URL

# 3. Import schema + demo seeds
php database/install.php
#    — or —
mysql -u root -p medistock_cloud < database/database.sql

# 4. Lock the installer
#    set APP_INSTALLED=1 in .env and delete database/install.php on public servers
```

## 3. Web server configuration
- **Document root:** `public/` — this keeps `app/`, `config/`, `database/`
  and `.env` outside the web root.
- **Apache:** enable `mod_rewrite`; the app uses plain `.php` URLs, so no
  rewrite rules are required.
- **Nginx:** standard `try_files $uri $uri/ =404;` + PHP-FPM passthrough.
- Force HTTPS (Let's Encrypt) and set `APP_URL` to the https URL.

## 4. Environment variables
See `.env.example`. Key values: `APP_ENV=production`, `APP_DEBUG=false`,
`APP_URL`, `DB_*`. Payment gateway variables stay blank when using manual
payment recording (payments then remain *pending* until a Super Admin
verifies them — by design).

## 5. Demo deployment on Vercel (static showcase)
Deploy the `vercel-demo/` folder as a static Vercel project (no build
command, output = the folder itself). It replicates the landing page, the
pharmacy dashboard and the POS screen with mock data in the production
brand palette, fully responsive. Suggested URL pattern:
`medistock-cloud.vercel.app`. The demo is a showcase only — it never
touches real pharmacy data.

## 6. Backups & restore
```bash
# Nightly backup (cron)
mysqldump --single-transaction -u medistock -p medistock_cloud | gzip > /backups/medistock-$(date +%F).sql.gz
# Restore
gunzip < /backups/medistock-YYYY-MM-DD.sql.gz | mysql -u medistock -p medistock_cloud
```
Keep at least 14 daily backups, test a restore monthly, and copy backups
off-server. Tenant logos live in `public/assets/uploads/` — include that
directory in file backups.

## 7. Production checklist
- [ ] `APP_DEBUG=false`, installer removed/locked
- [ ] Demo passwords changed (see README demo credentials)
- [ ] HTTPS enforced, `APP_URL` correct
- [ ] Least-privilege DB user, `.env` not committed
- [ ] Nightly backups running + one restore tested
- [ ] PHP error log monitored (`storage/logs/php-errors.log`)

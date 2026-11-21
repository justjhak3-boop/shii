# CampusFind
A mobile-responsive campus lost-and-found website prototype based on the linked requirements.

## Local website
The preview server is available at http://localhost:4173

From this folder, start or restart it with:
```sh
node server.mjs
```

The preview includes a dashboard, report browsing and search, lost/found report forms, possible matches, claim review, notifications, basic admin report/user/activity pages, and a messages preview. Demo data and report changes persist in the browser with localStorage.

## Project files
- `index.html`, `styles.css`, `app.js`: responsive interactive browser prototype.
- `server.mjs`: lightweight local preview server.
- `database/campusfind.sql`: MySQL schema for users, categories, reports, matches, claims, notifications, messages and activity logs.
- `backend/index.php`: Core PHP JSON API source.
- `backend/config`, `backend/middleware`, `backend/services`: PDO setup, session/CSRF/role helpers, matching and optional email notification.

The PHP API source is not connected to the browser preview yet. Preview authentication, messaging, accounts, and admin data are demonstrations. The local environment has Node.js but no PHP or MySQL, so the API and database could not be started here.

## Run the PHP API with XAMPP
1. Copy this folder under XAMPP's `htdocs`.
2. Import `database/campusfind.sql` with phpMyAdmin.
3. Set `CAMPUSFIND_DB_HOST`, `CAMPUSFIND_DB_NAME`, `CAMPUSFIND_DB_USER`, and `CAMPUSFIND_DB_PASSWORD` for the PHP server.
4. Set `CAMPUSFIND_ALLOWED_ORIGINS` to the exact frontend origin.
5. Enable PHP PDO MySQL and mbstring extensions.
6. The API entry point is `backend/index.php`; configure Apache rewrite rules before using clean `/api/...` routes.

Email delivery needs a configured PHP mail transport and `CAMPUSFIND_MAIL_FROM`. The matching process only suggests leads; it does not confirm ownership.

The university name, official logo, colors and campus location directory were not specified, so the preview uses generic campus wording and sample listings.

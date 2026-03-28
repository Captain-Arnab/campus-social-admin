# Campus Social — Admin Panel

Admin panel for **Campus Social** (College Connect), a college event management system. Manage events, users, approvals, and reports from a single dashboard.

## Features

- **Authentication** — Admin and Subadmin login with role-based access
- **Dashboard** — Overview of events (pending, hold, live), quick actions, and recent requests
- **Event management** — Approve, hold, or reject events; edit event details; view event details
- **User management** — View and manage registered users; user profiles
- **Reports** — Download event/attendance reports
- **API** — REST-style endpoints for the main app (events, users, volunteers, OTP, etc.)
- **Push notifications** — Firebase Cloud Messaging (FCM); organizers can send meeting updates to volunteers and participants

## Requirements

- **PHP** 7.4+ (8.x recommended)
- **MySQL** 5.7+ or **MariaDB** 10.4+
- **Web server** — Apache (e.g. XAMPP) or nginx with PHP-FPM
- **Composer** (for API dependencies in `api/`)

## Setup

### 1. Clone or copy the project

Place the `admin` folder in your web root (e.g. `htdocs/campus_social/admin` for XAMPP).

### 2. Database

1. Create a MySQL/MariaDB database (e.g. `college_event_db`).
2. Import the schema and seed data:
   ```bash
   mysql -u root -p college_event_db < college_event_db.sql
   ```
   Or use phpMyAdmin: create database → Import → choose `college_event_db.sql`.

### 3. Database configuration (required)

The app uses two DB config files that are **not** in the repo (see `.gitignore`). Create them with your credentials.

**Root `db.php`** (used by the admin panel pages):

```php
<?php
$host = "localhost";
$user = "root";           // your DB user
$pass = "";               // your DB password
$dbname = "college_event_db";

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
```

**`api/db.php`** — The API **must use the same database** as the admin panel. Otherwise events created from the app will not appear for approval or in the events list after approval.

- **Recommended:** Copy `api/db.example.php` to `api/db.php`. The example uses the admin panel’s connection (`../db.php`) so one config covers both.
- **Alternatively:** Create `api/db.php` with the same `$dbname` (and host/user/pass) as root `db.php`.

> **Important:** Never commit `db.php` or `api/db.php` — they contain credentials and are listed in `.gitignore`.

### 4. API dependencies (for email/OTP etc.)

From the project root:

```bash
cd api
composer install
```

### 5. Uploads (optional)

Ensure the `uploads/` directory exists and is writable by the web server if the app stores event images or other files there.

### 6. Push notifications (Firebase FCM)

To enable organizer-to-volunteer/participant push notifications:

1. **Run the notification migrations** (after the main DB schema is imported):
   ```bash
   mysql -u root -p college_event_db < api/migrations/add_notification_tables.sql
   mysql -u root -p college_event_db < api/migrations/add_celebration_days.sql
   ```
   The second migration adds the **Celebration Days** table (21 occasions for 2026 and 2027: New Year, Republic Day, Holi, Diwali, etc.) used for notification dates in the app.

2. **Create a Firebase project** (e.g. micampus-app) and add an Android app with package `in.co.micampus.app`. The Android app uses `google-services.json` for FCM.

3. **Server key for sending from API:** In [Firebase Console](https://console.firebase.google.com) → Project Settings → Service accounts → **Generate new private key**. Save the JSON file as `api/firebase-service-account.json` (this file is in `.gitignore` — do not commit it). The API uses this to send push notifications when organizers send messages.

4. **Optional:** To override project ID or path, create `api/firebase_config.local.php` and set `$firebase_project_id` and/or `$firebase_service_account_path`.

**Notification API endpoints:**

- **POST `api/register_fcm_token.php`** — Android app registers the device FCM token after user login. Body: `{"user_id": 1, "fcm_token": "...", "device_id": "optional"}`.
- **GET `api/notification_dates.php`** — Returns list of notification dates. Query: `?from=YYYY-MM-DD` (optional `&to=YYYY-MM-DD`, `&include_events=1`, `&include_celebrations=1` to include Celebration Days).
- **GET `api/celebration_dates.php`** — Returns Celebration Days only (2026/2027). Query: `?from=YYYY-MM-DD` (optional `&to=YYYY-MM-DD`, `&year=2026`).
- **POST `api/send_event_notification.php`** — Organizer sends a text message to volunteers and/or participants. Body: `{"event_id": 1, "organizer_id": 1, "message": "Meeting at 3 PM tomorrow", "recipient_type": "volunteers"|"participants"|"both"}`. Only the event organizer can call this.

### 7. Default login

After importing `college_event_db.sql`, you can log in as:

- **Admin:** username `admin`, password `password123`  
  Change this password in production.

## Project structure

```
admin/
├── api/                 # API endpoints (events, users, OTP, notifications, etc.)
│   ├── db.php           # DB config for API (create locally, in .gitignore)
│   ├── composer.json    # PHP dependencies (e.g. PHPMailer)
│   ├── firebase_config.php   # FCM project ID and service account path
│   ├── fcm_helper.php       # FCM v1 send helper (no Composer deps)
│   ├── register_fcm_token.php
│   ├── notification_dates.php
│   ├── send_event_notification.php
│   ├── celebration_dates.php
│   ├── migrations/add_notification_tables.sql
│   ├── migrations/add_celebration_days.sql
│   └── ...
├── assets/              # CSS, JS, images for admin UI
├── uploads/             # User-uploaded files (e.g. event images)
├── db.php               # DB config for admin panel (create locally, in .gitignore)
├── index.php            # Login page
├── dashboard.php        # Main dashboard
├── events.php           # Event listing/management
├── edit_event.php       # Edit event
├── event_details.php    # Event detail view
├── approve.php          # Approve/hold/reject events
├── users.php            # User listing
├── manage_user.php      # Manage single user
├── user_profile.php     # User profile view
├── download_report.php  # Report download
├── sidebar.php          # Shared sidebar
├── logout.php           # Logout
├── college_event_db.sql # Database schema and seed data
├── .gitignore           # Ignores db.php, api/db.php, vendor, uploads, etc.
└── README.md            # This file
```

## Troubleshooting

- **Events from the app don’t show for approval or in the events list** — The API and admin panel must use the same database. Use `api/db.example.php` (copy to `api/db.php`) so the API uses the admin `db.php`, or set the same `$dbname` in both configs.

## Security notes

- Change the default admin password after first login.
- Use strong, unique DB passwords and restrict DB user privileges.
- Keep `db.php` and `api/db.php` out of version control (they are in `.gitignore`).
- In production, use HTTPS and secure session settings.


# Campus Social — Admin Panel

Admin panel for **Campus Social** (College Connect), a college event management system. Manage events, users, approvals, and reports from a single dashboard.

## Features

- **Authentication** — Admin and Subadmin login with role-based access
- **Dashboard** — Overview of events (pending, hold, live), quick actions, and recent requests
- **Event management** — Approve, hold, or reject events; edit event details; view event details
- **User management** — View and manage registered users; user profiles
- **Reports** — Download event/attendance reports
- **API** — REST-style endpoints for the main app (events, users, volunteers, OTP, etc.)

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

**`api/db.php`** — Same structure as above; used by the API. Copy the same settings (or your API-specific DB user) into `api/db.php`.

> **Important:** Never commit `db.php` or `api/db.php` — they contain credentials and are listed in `.gitignore`.

### 4. API dependencies (for email/OTP etc.)

From the project root:

```bash
cd api
composer install
```

### 5. Uploads (optional)

Ensure the `uploads/` directory exists and is writable by the web server if the app stores event images or other files there.

### 6. Default login

After importing `college_event_db.sql`, you can log in as:

- **Admin:** username `admin`, password `password123`  
  Change this password in production.

## Project structure

```
admin/
├── api/                 # API endpoints (events, users, OTP, etc.)
│   ├── db.php           # DB config for API (create locally, in .gitignore)
│   ├── composer.json    # PHP dependencies (e.g. PHPMailer)
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

## Security notes

- Change the default admin password after first login.
- Use strong, unique DB passwords and restrict DB user privileges.
- Keep `db.php` and `api/db.php` out of version control (they are in `.gitignore`).
- In production, use HTTPS and secure session settings.


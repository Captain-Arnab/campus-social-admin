<?php
/**
 * Firebase / FCM configuration for push notifications.
 * Uses project from your Firebase app (micampus-app).
 *
 * For sending from server you need a SERVICE ACCOUNT key (not google-services.json).
 * 1. Firebase Console → Project Settings → Service accounts
 * 2. Generate new private key → save JSON somewhere outside web root or in api/ with restricted access
 * 3. Set the path below or use environment variable FIREBASE_SERVICE_ACCOUNT_PATH
 */
$firebase_project_id = getenv('FIREBASE_PROJECT_ID') ?: 'micampus-app';
$firebase_service_account_path = getenv('FIREBASE_SERVICE_ACCOUNT_PATH') ?: __DIR__ . '/firebase-service-account.json';

// Optional: allow config override from local file (e.g. api/firebase_config.local.php)
if (file_exists(__DIR__ . '/firebase_config.local.php')) {
    include __DIR__ . '/firebase_config.local.php';
}

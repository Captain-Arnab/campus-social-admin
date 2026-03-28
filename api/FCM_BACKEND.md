# FCM: Backend requirements for push notifications

For volunteers and participants to **see** notifications when the organizer sends a message or when **scheduled reminders** are sent:

## 1. App package (must match Firebase)

- **Package name** in Firebase Console and in `google-services.json` must match the app: **`co.micampus.app`**.
- **SHA certificate fingerprints**: Add debug and release SHA-1/SHA-256 in Project settings → Your apps → MiCampus App → Add fingerprint.

## 2. FCM message format (notification + data)

Send messages with **both** `notification` and `data` so they show when the app is in background:

```json
{
  "message": {
    "token": "<recipient_fcm_token>",
    "notification": {
      "title": "Event update",
      "body": "<organizer message>"
    },
    "android": {
      "priority": "high",
      "notification": {
        "channel_id": "high_importance_channel"
      }
    },
    "data": {
      "type": "organizer_message",
      "event_id": "<id>"
    }
  }
}
```

- **`notification`** is required for the system to show a heads-up when the app is in background.
- **`android.notification.channel_id`** must be **`high_importance_channel`** (matches the app).

## 3. Token registration (required for “0 recipients” fix)

- The app sends the FCM token to the backend after login and on refresh. You **must** have **`register_fcm_token.php`** in your `api/` folder so tokens are saved.
- Copy **`docs/register_fcm_token.php`** to your `api/` directory (same folder as `db.php`). It accepts **POST** with `user_id` and `fcm_token` (optional `device_id`) and stores them in **`user_fcm_tokens`**.
- If this endpoint is missing or not deployed, **`user_fcm_tokens`** stays empty and you get **“Notification sent to 0 recipients”**. After adding the file, users must open the app and stay logged in at least once so the app can register their token.
- Optional: add Bearer-token validation in the script so only the authenticated user can register their own `user_id` (see comments in the file).

## 4. Scheduled greeting notifications at 10 AM IST

- On **listed days**, **every user** (all app users with FCM tokens) receives a **greeting** notification at **10:00 AM IST**.
- **Listed days** come from:
  - **`notification_dates`**: rows where `notify_date` = today (custom greetings; title/message from the table or event).
  - **`celebration_days`**: rows where `occasion_date` = today (e.g. “Happy Holi!”, “Happy Republic Day!”).
- Use the script **`send_scheduled_notifications.php`** (in `docs/`; copy to your `api/` folder next to `db.php` and `fcm_helper.php`).
- **Cron (10 AM IST daily):**

  ```bash
  # Run every day at 10:00 AM IST (adjust path to your api directory)
  0 10 * * * cd /path/to/your/api && php send_scheduled_notifications.php
  ```

  On a server that uses UTC, 10 AM IST = 04:30 UTC:

  ```bash
  30 4 * * * cd /path/to/your/api && php send_scheduled_notifications.php
  ```

- The script sends each greeting to **all users** (all tokens in **`user_fcm_tokens`**), not only volunteers/participants.
- Ensure **`fcm_helper.php`** sends with **`android.notification.channel_id`** = `high_importance_channel` so notifications show correctly on Android.

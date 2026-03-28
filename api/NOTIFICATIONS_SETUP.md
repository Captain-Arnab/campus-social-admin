# Push notifications – "0 recipients" fix

## Why you see "Notification sent to 0 recipients" even with tokens in DB

Your `user_fcm_tokens` table has rows (e.g. user_id 21, 27), but the **organizer “Send notification”** flow still reports 0 recipients. That happens when the script that sends (e.g. **send_event_notification.php**) is missing, uses a **different database**, or has a bug.

## 1. Use one database everywhere

All API scripts must use the **same** `db.php` and the **same** database name:

- In **db.php** use: `$dbname = "micampus_college_event_db";` (as in your current db.php).
- **register_fcm_token.php**, **send_event_notification.php**, and **send_scheduled_notifications.php** must all `require_once __DIR__ . '/db.php'` from the same `api/` folder so they connect to `micampus_college_event_db` and see the same `user_fcm_tokens` table.

If one script uses another config (e.g. `college_event_db`) or another `db.php`, it will not see the tokens and will report 0 recipients.

## 2. Add the organizer send script

The app calls **send_event_notification.php** when the organizer taps “Send notification” (event detail → volunteers/participants).

1. Copy **docs/send_event_notification.php** to your API folder: **api/send_event_notification.php** (same directory as **db.php** and **fcm_helper.php**).
2. Ensure **api/db.php** connects to **micampus_college_event_db** (as in the snippet you shared).
3. Ensure **fcm_helper.php** exists in `api/` and is the one that sends FCM (e.g. uses your Firebase service account).

The script:

- Accepts POST: `event_id`, `organizer_id`, `message`, `recipient_type` (volunteers | participants | both).
- Checks that the authenticated user is the organizer of the event.
- Loads volunteers and/or participants for that event, then their FCM tokens from **user_fcm_tokens**.
- Sends the message via FCM and returns `push_sent` (number of devices that received the notification).

## 3. Quick check

- In phpMyAdmin (or any client) on **micampus_college_event_db** run:  
  `SELECT * FROM user_fcm_tokens;`  
  You should see the same rows you expect.
- In the same DB, ensure **events**, **volunteers**, and **participant** tables exist and have data for the event you’re testing (e.g. event 38 has participant user_id 21; user_fcm_tokens has tokens for user_id 21).

After **send_event_notification.php** is in place and uses the same **db.php** (same DB), “Notification sent to 0 recipients” should become “Notification sent to N recipient(s)” when there are tokens for that event’s volunteers/participants.

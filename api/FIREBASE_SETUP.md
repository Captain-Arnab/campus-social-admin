# Firebase service account — push notifications

The file `firebase-service-account.json` is **not** in the repo (it contains a secret key). You must create it from Firebase Console.

## Steps

1. Open [Firebase Console](https://console.firebase.google.com) and select your project (**micampus-app**).

2. Go to **Project settings** (gear icon) → **Service accounts**.

3. Click **Generate new private key** (or “Add key” / “Create service account” if you don’t have one yet).

4. A JSON file will download. It looks like the structure in `firebase-service-account.json.example`.

5. Rename or copy that file to:
   ```
   api/firebase-service-account.json
   ```
   Put it inside the `api/` folder (same folder as `firebase_config.php`).

6. **Security:** Keep this file out of version control (it’s already in `.gitignore`). Do not commit it or expose it on the web.

After this, the API can send push notifications when organizers use “Send notification” to volunteers/participants.

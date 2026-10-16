# Administrator management

## Create the first administrator

Use the CLI-only setup command described in the root [README](../README.md). It reads credentials from environment variables, requires a password of at least 12 characters, hashes the password, and refuses to overwrite an existing account.

## Manage administrator accounts

Sign in using the Admin Login option, then open `/backend/api/manage_admin.php` on the API host. The page requires an active administrator session; state-changing forms use POST and a session CSRF token. Serve the page only over HTTPS outside local development.

## Operational notes

- Never use a shared/default administrator password.
- Do not put passwords in URLs, source control, SQL files, or application logs.
- Back up the database before deleting administrator records or changing production access.
- Use a trusted database administration tool for emergency account recovery; store only `password_hash()`-generated hashes, never plaintext passwords.

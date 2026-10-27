# Email Setup Instructions

OTP codes, password resets and the Document Sender all send mail through SMTP. The credentials live in
`.env` at the project root (never committed); `config/email.php` and `config/email_config.php` read them.

## Gmail SMTP (recommended)

1. **Enable 2-Factor Authentication** on the Gmail account.
2. **Generate an App Password**: Google Account → Security → 2-Step Verification → App passwords → "Mail".
3. **Put it in `.env`**:

   ```
   MAIL_HOST=smtp.gmail.com
   MAIL_PORT=587
   MAIL_USERNAME=your-actual-email@gmail.com
   MAIL_PASSWORD=your-16-character-app-password
   MAIL_FROM_EMAIL=your-actual-email@gmail.com
   MAIL_FROM_NAME=LGU System
   ```

If an app password was ever committed to the repository, revoke it in Google and create a new one.

## Testing without sending (Document Sender only)

In `config/email_config.php`, change `'local_mode' => false` to `'local_mode' => true`. Emails are then
written to the error log instead of being sent.

## Testing

1. Log in (the OTP email is the quickest test), or send a document from the Document Sender page.
2. Check the inbox, including spam.
3. If it fails, check the Apache error log (XAMPP: `C:\xampp\apache\logs\error.log`).

## Troubleshooting

- **"Authentication failed"**: check the app password in `.env`.
- **"Connection refused"**: check the internet connection and `MAIL_HOST` / `MAIL_PORT`.
- **Still no emails**: check spam/junk folders.

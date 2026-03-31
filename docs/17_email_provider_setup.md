# Email Provider Setup Guide

ScamBuster uses standard IMAP/SMTP protocols for email — no Gmail OAuth required.

## Supported Providers

| Provider | IMAP Host | Port | SMTP DSN | Auth Method |
|----------|-----------|------|----------|-------------|
| **Gmail** | `imap.gmail.com` | 993 | `smtps://user:apppass@smtp.gmail.com:465` | App Password |
| **Outlook/O365** | `outlook.office365.com` | 993 | `smtp://user:pass@smtp.office365.com:587` | App Password |
| **Yahoo** | `imap.mail.yahoo.com` | 993 | `smtps://user:apppass@smtp.mail.yahoo.com:465` | App Password |
| **ProtonMail** | `127.0.0.1` | 1143 | `smtp://user:bridgepass@127.0.0.1:1025` | Bridge (paid) |
| **Fastmail** | `imap.fastmail.com` | 993 | `smtps://user:apppass@smtp.fastmail.com:465` | App Password |
| **Custom (Dovecot)** | `mail.domain.com` | 993 | `smtp://user:pass@mail.domain.com:587` | Password |

## Gmail Setup (2 minutes)

1. Go to https://myaccount.google.com/security
2. Enable **2-Step Verification** (required for App Passwords)
3. Go to https://myaccount.google.com/apppasswords
4. Select "Mail" > "Other (Custom name)" > type "ScamBuster" > **Create**
5. Copy the 16-character App Password (e.g., `abcd efgh ijkl mnop`)

In your `.env`:
```env
HONEYPOT_IMAP_HOST=imap.gmail.com
HONEYPOT_IMAP_PORT=993
HONEYPOT_IMAP_USER=your-honeypot@gmail.com
HONEYPOT_IMAP_PASSWORD=abcdefghijklmnop
HONEYPOT_IMAP_SECURE=true

MAILER_DSN=smtps://your-honeypot@gmail.com:abcdefghijklmnop@smtp.gmail.com:465
```

## MAILER_DSN Format

Symfony Mailer uses a DSN (Data Source Name) string:

- `smtps://` = TLS implicit (port 465) — use for Gmail, Yahoo
- `smtp://` = STARTTLS (port 587) — use for Outlook, custom servers

Format: `smtp[s]://username:password@host:port`

**Special characters in password**: URL-encode them:
| Character | Encoded |
|-----------|---------|
| `@` | `%40` |
| `:` | `%3A` |
| `/` | `%2F` |
| `#` | `%23` |

## Known Limitations

- **Microsoft 365 Business**: Some tenants disable IMAP by default (admin must enable it)
- **SMTP Message-ID**: The backend generates a local Message-ID; the SMTP server may assign a different one
- **IMAP polling**: 1-minute interval (n8n limitation). Gmail push notifications are not available with IMAP
- **Attachments**: IMAP "Simple" format does not include attachments inline. Attachment parsing is handled by the backend

## Troubleshooting

**IMAP connection fails**: Verify App Password (not regular password), check 2FA is enabled, check host/port
**SMTP send fails**: Check MAILER_DSN format, verify smtps:// vs smtp:// matches the port
**Emails not threading**: Verify the backend `/compose` endpoint returns correct `in-reply-to` and `references` headers

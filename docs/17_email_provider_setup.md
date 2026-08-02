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

## Adding one or several mailboxes

ScamBuster resolves the destination account from the **recipient address** of each
inbound message, so no node logic or backend code changes when you add a mailbox.
Each new mailbox needs three things: a row in the backend, its own IMAP trigger in
n8n, and its address excluded from IOC extraction. Budget a few minutes per mailbox.

### Step 1 — Register the mailbox in the backend

Do this **first**. If mail lands on a mailbox with no backend row, ingestion throws
`Unknown account_id` — and because the IMAP trigger marks messages as read before
the failure while searching only `UNSEEN`, those messages are never re-fetched.
They are lost, not queued.

```bash
bin/console app:mail-account:add \
  --owner-id=<owner-uuid> \
  --email=trap2@your-domain.com \
  --smtp-dsn='smtps://trap2@your-domain.com:apppass@smtp.your-domain.com:465' \
  --label="Support-desk trap" \
  --endpoint=imap.your-domain.com
```

This stores the reply-from address and the per-account SMTP DSN (encrypted at
rest), so each mailbox replies from its own address. Repeat once per mailbox.

`--label` is optional but recommended: a mailbox without one displays as `--` in the
dashboard's *Mailbox* column and cannot be picked in its mailbox filter. `--smtp-dsn`
is optional too — omit it and the mailbox replies through the global `MAILER_DSN`,
which misaligns DKIM/SPF when the reply-from domain differs.

### Step 2 — Give the mailbox its own IMAP trigger in n8n

An `IMAP Email Trigger` node binds exactly **one** IMAP credential, so one node polls
exactly one mailbox. Duplicate `WF-INTAKE-EMAIL-V2`, create an IMAP credential for
the new mailbox, and point the **duplicate's** trigger node at it.

> Do not repoint the existing workflow's node at the new credential — that moves your
> first mailbox onto the second one instead of adding it.

One workflow per mailbox is the recommended layout. Several trigger nodes inside a
single workflow do work (n8n activates every trigger node), but n8n aborts activation
of the entire workflow if any one trigger fails to start — so one expired app password
would stop collection on every mailbox sharing that workflow.

Apply the [reliable IMAP polling settings](08_getting_started.md#reliable-imap-polling-for-the-n8n-intake-workflows)
to every new trigger, or that mailbox will drop messages.

### Step 3 — Exclude the new address from IOC extraction

Add the address to `HONEYPOT_EMAIL_ADDRESSES` in `.env` (comma-separated; it defaults
to `HONEYPOT_IMAP_USER` alone). Skip this and your own honeypot address is extracted
as an IOC and shipped to your CTI feeds.

If your honeypots share a domain you own, set `HONEYPOT_DOMAINS` instead — one entry
covers every mailbox on that domain, present and future. Restart the backend after
editing either variable.

### Why no code change is needed

The intake workflow sends each message with an **empty `account_id`**. The backend
then matches the recipient (`To`, then `Delivered-To`, `Cc`) against the active
registered mailboxes and attaches the conversation to the right account. You can
still pass an explicit `account_id` to force a specific account if ever needed.

> Make sure the `--email` you register exactly matches the address the honeypot
> receives mail at; matching is case-insensitive, and disabled accounts are skipped.

## Known Limitations

- **Microsoft 365 Business**: Some tenants disable IMAP by default (admin must enable it)
- **SMTP Message-ID**: The backend generates a local Message-ID; the SMTP server may assign a different one
- **IMAP polling**: 1-minute interval (n8n limitation). Gmail push notifications are not available with IMAP
- **Attachments**: IMAP "Simple" format does not include attachments inline. Attachment parsing is handled by the backend

## Troubleshooting

**IMAP connection fails**: Verify App Password (not regular password), check 2FA is enabled, check host/port
**SMTP send fails**: Check MAILER_DSN format, verify smtps:// vs smtp:// matches the port
**Emails not threading**: Verify the backend `/compose` endpoint returns correct `in-reply-to` and `references` headers

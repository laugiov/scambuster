#!/usr/bin/env python3
"""Live credential checks for `make doctor`.

`doctor.sh` only knows whether a variable is *set*. This script answers the
question that actually matters — do the mailbox, the SMTP relay and the LLM
key still *work*? An expired API key or a revoked app password looks perfectly
valid in `.env`, installs without a warning, and only surfaces later as scam
mail that is captured but never classified or answered.

Output: one `STATUS|message` line per check, rendered by doctor.sh.
Exit code: number of failed checks (0 = all good).

Secrets are never printed — only host names, addresses and outcomes.
"""

from __future__ import annotations

import json
import os
import re
import smtplib
import ssl
import sys
import urllib.error
import urllib.parse
import urllib.request

TIMEOUT = 15

# Placeholders shipped in .env.dist — treated as "not configured", not "broken".
PLACEHOLDERS = {
    "your-honeypot@gmail.com",
    "your-app-password-here",
    "sk-your-api-key-here",
    "null://null",
}


def load_env(path: str = ".env") -> dict[str, str]:
    env: dict[str, str] = {}
    try:
        with open(path, encoding="utf-8") as fh:
            for line in fh:
                match = re.match(r"^([A-Z0-9_]+)=(.*)$", line.rstrip("\n"))
                if match and match.group(1) not in env:
                    env[match.group(1)] = match.group(2).strip().strip("'\"")
    except OSError:
        pass
    return env


def configured(value: str | None) -> bool:
    return bool(value) and value not in PLACEHOLDERS


def emit(status: str, message: str) -> None:
    print(f"{status}|{message}", flush=True)


def check_imap(env: dict[str, str]) -> int:
    host = env.get("HONEYPOT_IMAP_HOST", "")
    user = env.get("HONEYPOT_IMAP_USER", "")
    password = env.get("HONEYPOT_IMAP_PASSWORD", "")

    if not (configured(host) and configured(user) and configured(password)):
        emit("SKIP", "IMAP — not configured (demo mode)")
        return 0

    import imaplib

    try:
        port = int(env.get("HONEYPOT_IMAP_PORT") or 993)
        conn = imaplib.IMAP4_SSL(host, port, timeout=TIMEOUT)
        conn.login(user, password)
        conn.select("INBOX")
        _, data = conn.search(None, "UNSEEN")
        unseen = len(data[0].split()) if data and data[0] else 0
        conn.logout()
        emit("OK", f"IMAP — {user} on {host}:{port} ({unseen} unread)")
        return 0
    except Exception as exc:  # noqa: BLE001 — any failure is a failed check
        emit("FAIL", f"IMAP — login to {host} failed: {type(exc).__name__}: {exc}")
        return 1


def check_smtp(env: dict[str, str]) -> int:
    dsn = env.get("MAILER_DSN", "")

    if not configured(dsn):
        emit("SKIP", "SMTP — MAILER_DSN not configured (replies cannot be sent)")
        return 0

    try:
        parsed = urllib.parse.urlparse(dsn)
        host = parsed.hostname
        if not host:
            emit("FAIL", "SMTP — MAILER_DSN has no host (check the DSN format)")
            return 1

        port = parsed.port or (465 if parsed.scheme == "smtps" else 587)
        user = urllib.parse.unquote(parsed.username or "")
        password = urllib.parse.unquote(parsed.password or "")

        if parsed.scheme == "smtps":
            server = smtplib.SMTP_SSL(host, port, timeout=TIMEOUT)
        else:
            server = smtplib.SMTP(host, port, timeout=TIMEOUT)
            server.starttls(context=ssl.create_default_context())

        if user:
            server.login(user, password)
        server.quit()
        emit("OK", f"SMTP — authenticated on {host}:{port}")
        return 0
    except Exception as exc:  # noqa: BLE001
        emit("FAIL", f"SMTP — {type(exc).__name__}: {exc}")
        return 1


def _http_status(url: str, headers: dict[str, str]) -> tuple[int, str]:
    request = urllib.request.Request(url, headers=headers)
    try:
        with urllib.request.urlopen(request, timeout=TIMEOUT) as response:
            return response.status, response.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as exc:
        return exc.code, ""


def check_llm(env: dict[str, str]) -> int:
    provider = (env.get("LLM_PROVIDER") or "").lower()
    key = env.get("LLM_API_KEY", "")
    model = env.get("LLM_MODEL", "")

    if provider == "mock":
        emit("SKIP", "LLM — provider is 'mock' (no key needed, replies are synthetic)")
        return 0

    if provider == "ollama":
        base = env.get("OLLAMA_BASE_URL") or "http://localhost:11434"
        try:
            status, body = _http_status(f"{base}/api/tags", {})
            if status == 200:
                names = [m.get("name", "") for m in json.loads(body).get("models", [])]
                have = "available" if model in names else f"NOT pulled (have: {len(names)})"
                emit("OK", f"LLM — ollama reachable at {base}; model '{model}' {have}")
                return 0
        except Exception as exc:  # noqa: BLE001
            emit("FAIL", f"LLM — ollama unreachable at {base}: {type(exc).__name__}")
            return 1
        emit("FAIL", f"LLM — ollama at {base} answered HTTP {status}")
        return 1

    if not configured(key):
        emit("FAIL", f"LLM — provider '{provider}' needs LLM_API_KEY, which is unset")
        return 1

    if provider == "anthropic":
        url = "https://api.anthropic.com/v1/models"
        headers = {"x-api-key": key, "anthropic-version": "2023-06-01"}
    else:  # openai and OpenAI-compatible gateways
        url = "https://api.openai.com/v1/models"
        headers = {"Authorization": f"Bearer {key}"}

    try:
        status, body = _http_status(url, headers)
    except Exception as exc:  # noqa: BLE001
        emit("SKIP", f"LLM — could not reach {provider} ({type(exc).__name__}); key not verified")
        return 0

    if status == 200:
        try:
            ids = [m.get("id", "") for m in json.loads(body).get("data", [])]
            have = "available" if model in ids else "NOT in the account's model list"
            emit("OK", f"LLM — {provider} key valid; model '{model}' {have}")
        except (ValueError, AttributeError):
            emit("OK", f"LLM — {provider} key valid")
        return 0

    if status in (401, 403):
        emit("FAIL", f"LLM — {provider} rejected the key (HTTP {status}): expired, revoked or wrong project")
        return 1

    # Anything else (404 on a gateway, 429, 5xx) says nothing about the key.
    emit("SKIP", f"LLM — {provider} answered HTTP {status}; key not verified")
    return 0


def main() -> int:
    env = load_env()

    if not env:
        emit("FAIL", "no readable .env — run 'cp .env.dist .env' first")
        return 1

    return check_imap(env) + check_smtp(env) + check_llm(env)


if __name__ == "__main__":
    sys.exit(main())

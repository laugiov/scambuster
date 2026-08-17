#!/usr/bin/env python3
"""Deployment gate: did a chore PR change what runs in production, unannounced?

Chore pipeline only.

`chore-scope` already refuses application code in a chore PR. Its deny list is
`backend-symfony/{src,tests,migrations}` and `frontend-react/src` — and PR #63
walked straight through the gap: it modified `Dockerfile.prod`, the demo
Dockerfile and `docker-compose.yml`, and the gate passed, because production
build files are not application code by that definition.

They decide what runs in production and on the public demo. A change there can
break a deployment as thoroughly as a change in `src/`, and it went through a
pipeline whose whole promise is that chore means no behaviour change.

The fix is deliberately NOT to extend the deny list. PR #63 legitimately had to
touch those files — the CI it was repairing lives in them — and a guard that
forbids what the work requires gets bypassed rather than respected. So this is a
softer second tier:

    deny  (chore-scope)          application code. Hard failure, no escape hatch.
    warn  (here)                 deployment surface. Allowed, but it must be
                                 declared, and the declaration must say something.

    Deploy-impact: <what a deployer needs to know>

Declaring is cheap and always available. What is not available is saying
nothing, which is how a production build changes under a label that promises it
did not. The same shape as `Docs-impact:` — see check-docs-impact.py — because
one convention people already know beats two they have to look up.

Usage:
    check-deploy-impact.py --base <sha> --head <sha> --pr-body-file <path>

Output is the factory's standard objection format.
Exit codes: 0 pass, 1 blocking violation, 2 usage or environment error.
"""

from __future__ import annotations

import argparse
import fnmatch
import re
import subprocess
import sys
from pathlib import Path

# Free text after the colon. A leading "none" is allowed — "Deploy-impact: none
# — CI-only paths" reads naturally — but it has to be followed by a reason, so
# the marker is stripped before the substance is measured.
DECLARATION_RE = re.compile(r"^\s*Deploy-impact:\s*(.*)$", re.M | re.I)
NON_ANSWER_RE = re.compile(r"^(none|no|n/?a|nil)\s*[-—–:,]?\s*", re.I)
MIN_REASON = 10

# What a deployer would want to have been told about.
DEPLOY_SURFACE = (
    ("infra/docker/", "a container image or its entrypoint"),
    ("infra/monitoring/", "the monitoring stack"),
    ("docker-compose", "a compose stack definition"),
    (".env.dist", "the environment template"),
)


def changed_files(base: str, head: str) -> list[str]:
    try:
        out = subprocess.run(
            ["git", "diff", "--name-only", f"{base}...{head}"],
            check=True, capture_output=True, text=True,
        ).stdout
    except subprocess.CalledProcessError as exc:
        print(f"error: git diff failed: {exc.stderr.strip()}", file=sys.stderr)
        sys.exit(2)
    return [line for line in out.splitlines() if line]


def matches(path: str, prefix: str) -> bool:
    # `docker-compose` has to match at the repository root only, so that a file
    # like `docs/docker-compose-tips.md` does not trip the gate.
    if prefix == "docker-compose":
        return fnmatch.fnmatch(path, "docker-compose*.yml") or fnmatch.fnmatch(
            path, "docker-compose*.yaml"
        )
    return path.startswith(prefix)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", required=True)
    parser.add_argument("--head", default="HEAD")
    parser.add_argument("--pr-body-file", type=Path, required=True)
    args = parser.parse_args()

    if not args.pr_body_file.is_file():
        print(f"error: PR body file not found: {args.pr_body_file}", file=sys.stderr)
        return 2

    files = changed_files(args.base, args.head)
    touched = sorted({
        path for path in files
        for prefix, _ in DEPLOY_SURFACE
        if matches(path, prefix)
    })
    if not touched:
        print("No deployment surface touched. Deploy gate not applicable.")
        return 0

    labels = sorted({
        label for path in touched
        for prefix, label in DEPLOY_SURFACE
        if matches(path, prefix)
    })

    print(f"::warning::This chore PR changes the deployment surface ({', '.join(labels)}):")
    for path in touched:
        print(f"  {path}")

    match = DECLARATION_RE.search(args.pr_body_file.read_text(encoding="utf-8"))
    reason = NON_ANSWER_RE.sub("", match.group(1).strip()).strip() if match else ""

    if match and len(reason) >= MIN_REASON:
        print(f"\nADVISORY ;  ; deployment impact declared: {reason[:70]}")
        return 0

    if match:
        print(
            "\nBLOCKING ;  ; `Deploy-impact:` is present but states no reason"
        )
        print(
            "\n::error::The PR body has a `Deploy-impact:` line with nothing after it.\n"
            "A deployer needs a sentence, not a marker. For example:\n"
            "  Deploy-impact: rebuilds all three images; no runtime config changed",
            file=sys.stderr,
        )
        return 1

    print(
        f"\nBLOCKING ;  ; changes {', '.join(labels)} without a `Deploy-impact:` line"
    )
    print(
        "\n::error::A chore PR may change the deployment surface — this one does — "
        "but it has to say so.\nAdd a line to the PR body:\n"
        "  Deploy-impact: <what a deployer needs to know>\n\n"
        "Chore promises no behaviour change. These files decide what runs in "
        "production and on the public demo, so the promise needs a sentence "
        "behind it rather than a passing gate.",
        file=sys.stderr,
    )
    return 1


if __name__ == "__main__":
    sys.exit(main())

#!/usr/bin/env python3
"""Documentation gate: did a user-visible change update the documentation?

Feature pipeline only.

The factory had no documentation gate at all — only the PR template's
"Documentation updated if needed" checkbox, which nothing verifies. A checkbox
nobody checks is how a runbook silently goes stale.

The rule: when a PR changes something a reader of the docs would notice — an
HTTP controller, a route, a public DTO, a migration, or bundle configuration —
it must either touch `docs/`, or state in the PR body why it does not:

    Docs-impact: none — <reason>

Declaring "none" is a legitimate answer and is deliberately cheap. What is not
available is saying nothing: a human then has a sentence to disagree with,
instead of an unticked box to overlook.

Usage:
    check-docs-impact.py --base <sha> --head <sha> --pr-body-file <path>

Output is the factory's standard objection format.
Exit codes: 0 pass, 1 blocking violation, 2 usage or environment error.
"""

from __future__ import annotations

import argparse
import re
import subprocess
import sys
from pathlib import Path

DECLARATION_RE = re.compile(r"^\s*Docs-impact:\s*none\s*[-—–:]?\s*(.+)$", re.M | re.I)

# Changes a documentation reader would notice.
USER_VISIBLE = (
    ("backend-symfony/src/UI/Http/", "an HTTP controller"),
    ("backend-symfony/src/UI/Console/", "a console command"),
    ("backend-symfony/config/routes", "routing"),
    ("backend-symfony/config/packages/", "bundle configuration"),
    ("backend-symfony/migrations/", "a database migration"),
    ("frontend-react/src/pages/", "a UI page"),
    (".env.dist", "the environment template"),
    ("Makefile", "a make target"),
)

DOC_PREFIXES = ("docs/", "README.md", "CONTRIBUTING.md")


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
    if not files:
        print("No changed files.")
        return 0

    triggers = sorted({
        label for path in files
        for prefix, label in USER_VISIBLE
        if path.startswith(prefix)
    })
    if not triggers:
        print("Nothing user-visible changed. Documentation gate not applicable.")
        return 0

    touched_docs = [f for f in files if f.startswith(DOC_PREFIXES)]
    if touched_docs:
        print(f"User-visible change ({', '.join(triggers)}) and documentation updated:")
        for doc in touched_docs:
            print(f"  {doc}")
        return 0

    declaration = DECLARATION_RE.search(args.pr_body_file.read_text(encoding="utf-8"))
    if declaration:
        print(f"ADVISORY ;  ; documentation declared unaffected: {declaration.group(1).strip()[:70]}")
        print(f"\nDeclared no documentation impact despite changing {', '.join(triggers)}.")
        return 0

    print(
        f"BLOCKING ;  ; changes {', '.join(triggers)} without touching docs/ "
        f"or declaring why none is needed"
    )
    print(
        "\n::error::This PR changes something a documentation reader would notice "
        f"({', '.join(triggers)}) but updates no documentation.\n"
        "Either update docs/, or add a line to the PR body:\n"
        "  Docs-impact: none — <why the docs stay correct>",
        file=sys.stderr,
    )
    return 1


if __name__ == "__main__":
    sys.exit(main())

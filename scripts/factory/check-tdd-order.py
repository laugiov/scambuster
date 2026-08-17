#!/usr/bin/env python3
"""TDD gate: for every task, did the failing test land before the implementation?

Feature pipeline only. The bug and security pipelines already enforce test-first
by construction — their whole first step is a committed failing test.

The rule, per task `T###`:

    a commit touching only test files, citing that task,
    must appear BEFORE any commit touching application source for it.

That is checkable from history and cannot be reconstructed after the fact, which
is the point: a test written after the implementation only proves the code does
what it now does.

What is NOT checked here, and needs `qa-reviewer`: whether the test was ever seen
failing, and whether it asserts anything. An empty test file satisfies this gate.
Order is necessary, not sufficient.

Escape hatch: a commit whose body carries `TDD-exempt: <reason>` is reported as
ADVISORY instead of blocking. It never disappears — it lands in the gate report
where a human reads it.

Usage:
    check-tdd-order.py --base <sha> --head <sha>

Output is the factory's standard objection format.
Exit codes: 0 pass, 1 blocking violations, 2 usage or environment error.
"""

from __future__ import annotations

import argparse
import re
import subprocess
import sys

TASK_RE = re.compile(r"\bT\d{3}\b")
EXEMPT_RE = re.compile(r"^\s*TDD-exempt:\s*(.+)$", re.M)

# A file is a test if it lives in a test tree or is named like one.
TEST_PATTERNS = (
    "backend-symfony/tests/",
    "frontend-react/src/__tests__/",
)
TEST_SUFFIXES = (".test.ts", ".test.tsx", ".spec.ts", ".spec.tsx", "Test.php")

# Application source. Anything outside this — docs, CI, config, fixtures — is not
# behaviour, so it carries no TDD obligation.
SOURCE_PREFIXES = (
    "backend-symfony/src/",
    "frontend-react/src/",
)


def run(*args: str) -> str:
    try:
        return subprocess.run(args, check=True, capture_output=True, text=True).stdout
    except subprocess.CalledProcessError as exc:
        print(f"error: {' '.join(args)} failed: {exc.stderr.strip()}", file=sys.stderr)
        sys.exit(2)


def is_test_file(path: str) -> bool:
    return path.startswith(TEST_PATTERNS) or path.endswith(TEST_SUFFIXES)


def is_source_file(path: str) -> bool:
    return path.startswith(SOURCE_PREFIXES) and not is_test_file(path)


def commits(base: str, head: str) -> list[dict]:
    """Oldest first — order is the whole subject of this check."""
    raw = run("git", "log", "--reverse", "--no-merges",
              "--format=%H%x00%s%x00%b%x1e", f"{base}..{head}")
    out = []
    for record in raw.split("\x1e"):
        record = record.strip("\n")
        if not record:
            continue
        sha, subject, body = (record.split("\x00") + ["", ""])[:3]
        files = run("git", "show", "--pretty=", "--name-only", sha).split()
        out.append({
            "sha": sha,
            "subject": subject,
            "message": f"{subject}\n{body}",
            "files": files,
            "tasks": set(TASK_RE.findall(f"{subject}\n{body}")),
        })
    return out


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", required=True)
    parser.add_argument("--head", default="HEAD")
    args = parser.parse_args()

    history = commits(args.base, args.head)
    if not history:
        print(f"error: no non-merge commits in {args.base}..{args.head}", file=sys.stderr)
        return 2

    objections: list[str] = []
    tasks_with_test_first: set[str] = set()
    checked = 0

    for commit in history:
        files = commit["files"]
        if not files:
            continue

        touches_source = any(is_source_file(f) for f in files)
        only_tests = all(is_test_file(f) for f in files)

        # A test-only commit opens the door for every task it cites.
        if only_tests:
            tasks_with_test_first |= commit["tasks"]
            continue

        if not touches_source:
            continue  # docs, CI, config: no behaviour, no obligation

        checked += 1
        short = commit["sha"][:12]
        exempt = EXEMPT_RE.search(commit["message"])

        if not commit["tasks"]:
            objections.append(
                f"ADVISORY ; {short} ; implementation commit cites no task id, "
                f"so TDD order cannot be checked: {commit['subject'][:60]}"
            )
            continue

        uncovered = sorted(commit["tasks"] - tasks_with_test_first)
        if not uncovered:
            continue

        if exempt:
            objections.append(
                f"ADVISORY ; {short} ; TDD-exempt claimed for {', '.join(uncovered)}: "
                f"{exempt.group(1).strip()[:60]}"
            )
            # An exemption still opens the door, so the same task is not
            # reported again on every later commit.
            tasks_with_test_first |= commit["tasks"]
        else:
            objections.append(
                f"BLOCKING ; {short} ; implementation lands before any test-only commit "
                f"for {', '.join(uncovered)}: {commit['subject'][:50]}"
            )

    blocking = [o for o in objections if o.startswith("BLOCKING")]
    for objection in objections:
        print(objection)

    print(
        f"\n{checked} implementation commit(s) checked, {len(blocking)} blocking, "
        f"{len(objections) - len(blocking)} advisory",
        file=sys.stderr,
    )
    if blocking:
        print(
            "\nEach task needs its failing test committed first, in a commit touching "
            "only test files and citing the same T### id. Order is verifiable in "
            "history; a test written afterwards is not.",
            file=sys.stderr,
        )
    return 1 if blocking else 0


if __name__ == "__main__":
    sys.exit(main())

#!/usr/bin/env python3
"""Traceability gate: every task and every commit must cite a requirement id.

Feature pipeline only. Bug and security pipelines have no spec — their
specification is the failing test — so running this against them would fail a
correct change.

Checks, in order:

1. Every commit in the PR range cites at least one FR-### or SC-###.
   Merge commits are skipped: their message is generated, not authored.
2. If specs/<branch>/tasks.md exists, every task line cites at least one id.
3. Every cited id actually exists in specs/<branch>/spec.md. An invented id is
   worse than a missing one: it satisfies a naive check while tracing to
   nothing.

Usage:
    check-traceability.py --base <ref> --head <ref> [--spec-dir specs/<branch>]

Exit codes: 0 pass, 1 violations found, 2 usage or environment error.
Output is the factory's standard objection format, so the result can be pasted
straight into a gate report.
"""

from __future__ import annotations

import argparse
import re
import subprocess
import sys
from pathlib import Path

ID_RE = re.compile(r"\b(?:FR|SC)-\d{3}\b")

# A task line cites ids in a parenthesised group: "- [ ] T012 [P] (FR-003) …".
# A prose mention such as "(see FR-003)" is deliberately not a citation.
TASK_LINE_RE = re.compile(r"^\s*-\s*\[[ x]\]\s*T\d{3}")
TASK_CITATION_RE = re.compile(r"\((?:FR|SC)-\d{3}(?:,\s*(?:FR|SC)-\d{3})*\)")


def run(*args: str) -> str:
    try:
        return subprocess.run(
            args, check=True, capture_output=True, text=True
        ).stdout
    except subprocess.CalledProcessError as exc:
        print(f"error: {' '.join(args)} failed: {exc.stderr.strip()}", file=sys.stderr)
        sys.exit(2)


def commits_in_range(base: str, head: str) -> list[tuple[str, str]]:
    """(sha, subject) for non-merge commits in base..head."""
    out = run("git", "log", "--no-merges", "--format=%H%x00%s%x00%b%x1e", f"{base}..{head}")
    commits = []
    for record in out.split("\x1e"):
        record = record.strip("\n")
        if not record:
            continue
        sha, subject, body = (record.split("\x00") + ["", ""])[:3]
        commits.append((sha, f"{subject}\n{body}"))
    return commits


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", required=True)
    parser.add_argument("--head", default="HEAD")
    parser.add_argument("--spec-dir", default=None)
    args = parser.parse_args()

    objections: list[str] = []

    # --- known requirement ids, if a spec exists -------------------------
    known_ids: set[str] = set()
    spec_dir = Path(args.spec_dir) if args.spec_dir else None
    spec_file = spec_dir / "spec.md" if spec_dir else None

    if spec_file and spec_file.is_file():
        known_ids = set(ID_RE.findall(spec_file.read_text(encoding="utf-8")))
        print(f"spec: {spec_file} ({len(known_ids)} requirement ids)", file=sys.stderr)
    else:
        print(
            "warning: no spec.md found. Citations cannot be validated against a "
            "spec; only their presence is checked.",
            file=sys.stderr,
        )

    # --- 1. commits ------------------------------------------------------
    commits = commits_in_range(args.base, args.head)
    if not commits:
        print(f"error: no non-merge commits in {args.base}..{args.head}", file=sys.stderr)
        return 2

    for sha, message in commits:
        cited = set(ID_RE.findall(message))
        if not cited:
            objections.append(
                f"BLOCKING ; {sha[:12]} ; commit cites no requirement id: "
                f"{message.splitlines()[0][:80]}"
            )
        elif known_ids:
            unknown = sorted(cited - known_ids)
            if unknown:
                objections.append(
                    f"BLOCKING ; {sha[:12]} ; commit cites ids absent from the spec: "
                    f"{', '.join(unknown)}"
                )

    # --- 2. tasks --------------------------------------------------------
    tasks_file = spec_dir / "tasks.md" if spec_dir else None
    if tasks_file and tasks_file.is_file():
        for lineno, line in enumerate(tasks_file.read_text(encoding="utf-8").splitlines(), 1):
            if not TASK_LINE_RE.match(line):
                continue
            citation = TASK_CITATION_RE.search(line)
            if not citation:
                objections.append(
                    f"BLOCKING ; {tasks_file}:{lineno} ; task cites no requirement id: "
                    f"{line.strip()[:80]}"
                )
                continue
            if known_ids:
                unknown = sorted(set(ID_RE.findall(citation.group(0))) - known_ids)
                if unknown:
                    objections.append(
                        f"BLOCKING ; {tasks_file}:{lineno} ; task cites ids absent from "
                        f"the spec: {', '.join(unknown)}"
                    )
    else:
        print("note: no tasks.md; task citations not checked", file=sys.stderr)

    # --- 3. uncovered requirements (advisory) ----------------------------
    if known_ids:
        cited_anywhere: set[str] = set()
        for _, message in commits:
            cited_anywhere |= set(ID_RE.findall(message))
        if tasks_file and tasks_file.is_file():
            cited_anywhere |= set(ID_RE.findall(tasks_file.read_text(encoding="utf-8")))
        for missing in sorted(known_ids - cited_anywhere):
            objections.append(
                f"ADVISORY ; {missing} ; requirement is in the spec but no task or "
                f"commit cites it"
            )

    # --- report ----------------------------------------------------------
    blocking = [o for o in objections if o.startswith("BLOCKING")]
    for objection in objections:
        print(objection)

    print(
        f"\n{len(commits)} commits checked, {len(blocking)} blocking, "
        f"{len(objections) - len(blocking)} advisory",
        file=sys.stderr,
    )
    return 1 if blocking else 0


if __name__ == "__main__":
    sys.exit(main())

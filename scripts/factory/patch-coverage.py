#!/usr/bin/env python3
"""Patch coverage gate: are the lines this PR changed actually covered?

Answers "does this PR add untested code", which is what a reviewer cares about,
without running the suite a second time on the base branch. Project-level
"not below base" stays with Codecov (`target: auto` in codecov.yml).

Reads one or more Clover XML reports — the ones ci.yml already produces for the
unit+integration run and for E2E — and unions their covered lines, so a line
exercised only by E2E counts as covered.

Usage:
    patch-coverage.py --base <sha> --head <sha> --min 80 clover1.xml [clover2.xml]

Only added/modified lines in PHP files under backend-symfony/src are considered.
Deleted lines cannot be covered; test files are not the subject of the gate.

Exit codes: 0 at or above the threshold (or nothing coverable changed),
1 below it, 2 usage or environment error.
"""

from __future__ import annotations

import argparse
import subprocess
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

WATCHED_PREFIX = "backend-symfony/src/"


def changed_lines(base: str, head: str) -> dict[str, set[int]]:
    """{path: {line numbers added or modified}} from a unified diff."""
    try:
        diff = subprocess.run(
            ["git", "diff", "--unified=0", "--diff-filter=AM", f"{base}...{head}", "--", f"{WATCHED_PREFIX}*.php"],
            check=True, capture_output=True, text=True,
        ).stdout
    except subprocess.CalledProcessError as exc:
        print(f"error: git diff failed: {exc.stderr.strip()}", file=sys.stderr)
        sys.exit(2)

    result: dict[str, set[int]] = {}
    current: str | None = None
    for line in diff.splitlines():
        if line.startswith("+++ b/"):
            current = line[6:]
            result.setdefault(current, set())
        elif line.startswith("@@") and current:
            # @@ -old,count +new,count @@
            try:
                new_part = line.split("+", 1)[1].split(" ", 1)[0]
            except IndexError:
                continue
            start, _, count = new_part.partition(",")
            start_i, count_i = int(start), int(count or 1)
            result[current].update(range(start_i, start_i + count_i))
    return {p: lines for p, lines in result.items() if lines}


def coverage_from_clover(paths: list[Path]) -> tuple[dict[str, set[int]], dict[str, set[int]]]:
    """(coverable, covered) line numbers per repo-relative path."""
    coverable: dict[str, set[int]] = {}
    covered: dict[str, set[int]] = {}

    for path in paths:
        if not path.is_file():
            print(f"warning: clover report not found, skipping: {path}", file=sys.stderr)
            continue
        for file_el in ET.parse(path).getroot().iter("file"):
            name = file_el.get("name") or ""
            # Clover paths are absolute inside the container (/app/src/...).
            idx = name.find("/src/")
            if idx == -1:
                continue
            rel = WATCHED_PREFIX + name[idx + len("/src/"):]
            for line_el in file_el.iter("line"):
                if line_el.get("type") != "stmt":
                    continue
                num = int(line_el.get("num", 0))
                coverable.setdefault(rel, set()).add(num)
                if int(line_el.get("count", 0)) > 0:
                    covered.setdefault(rel, set()).add(num)
    return coverable, covered


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", required=True)
    parser.add_argument("--head", default="HEAD")
    parser.add_argument("--min", type=float, default=80.0)
    parser.add_argument("clover", nargs="+", type=Path)
    args = parser.parse_args()

    changed = changed_lines(args.base, args.head)
    if not changed:
        print(f"No PHP files changed under {WATCHED_PREFIX}. Patch coverage gate not applicable.")
        return 0

    coverable, covered = coverage_from_clover(args.clover)
    if not coverable:
        print(
            "error: no coverable lines found in any clover report. Treat this as "
            "'coverage did not run', not as 'coverage is fine'.",
            file=sys.stderr,
        )
        return 2

    total_relevant = 0
    total_covered = 0
    uncovered_report: list[tuple[str, list[int]]] = []

    for path, lines in sorted(changed.items()):
        relevant = lines & coverable.get(path, set())
        if not relevant:
            continue
        hit = relevant & covered.get(path, set())
        total_relevant += len(relevant)
        total_covered += len(hit)
        missing = sorted(relevant - hit)
        if missing:
            uncovered_report.append((path, missing))

    if total_relevant == 0:
        print("No coverable lines were changed (comments, signatures or config only).")
        return 0

    pct = 100.0 * total_covered / total_relevant
    print(f"Patch coverage: {total_covered}/{total_relevant} changed lines = {pct:.1f}% (min {args.min:.1f}%)")

    if uncovered_report:
        print("\nUncovered changed lines:")
        for path, missing in uncovered_report:
            shown = ", ".join(str(m) for m in missing[:15])
            more = f" (+{len(missing) - 15} more)" if len(missing) > 15 else ""
            print(f"  {path}: {shown}{more}")

    if pct < args.min:
        print(
            f"\n::error::Patch coverage {pct:.1f}% is below the {args.min:.1f}% threshold. "
            f"Cover the lines above, or state in the PR why they cannot be."
        )
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())

#!/usr/bin/env python3
"""Score a factory benchmark run: did the gates catch the defects you seeded?

Reads gate reports, extracts objections in the factory's standard format, and
compares them against a ground-truth file that lists the defects you injected.

    BLOCKING|ADVISORY ; requirement ID or failing-test path ; short description

Detection rule — implemented here, stated in README.md, and deliberately strict:

    DETECTED  a BLOCKING objection cites the defect's requirement id
    PARTIAL   only ADVISORY objections cite it. Someone noticed; nothing stopped.
    MISSED    no objection cites it at all

The asymmetry is the point. An advisory does not stop a pipeline, so counting it
as a catch would measure whether the reviewers *mentioned* the problem, when what
matters is whether the factory would have *shipped* it.

Usage:
    score.py --ground-truth ~/scambuster-benchmarks/run-01.yaml <gate-report>...
    score.py --ground-truth ~/bench/run-01.yaml --json specs/042-x/gate-reports/

Pass a directory and every *.md inside it is read.

The ground-truth path is an argument on purpose: it must live OUTSIDE the
worktree, where a pipeline agent cannot read it. See README.md.

Exit codes: 0 scored, 2 usage or input error. The score itself never sets a
non-zero exit — a benchmark measures, it does not gate.
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

try:
    import yaml
except ImportError:
    print("error: PyYAML is required (pip install pyyaml)", file=sys.stderr)
    sys.exit(2)

OBJECTION_RE = re.compile(r"^(BLOCKING|ADVISORY)\s*;\s*([^;]+?)\s*;\s*(.+)$")
REQUIREMENT_ID_RE = re.compile(r"^(?:FR|SC)-\d{3}$")


def repo_root() -> Path:
    """The repository this script belongs to, independent of the caller's cwd."""
    here = Path(__file__).resolve()
    try:
        import subprocess

        out = subprocess.run(
            ["git", "-C", str(here.parent), "rev-parse", "--show-toplevel"],
            check=True, capture_output=True, text=True,
        ).stdout.strip()
        if out:
            return Path(out).resolve()
    except Exception:
        pass
    # factory/benchmark/score.py -> repo root is two levels up.
    return here.parent.parent.parent


def is_inside_repo(path: Path) -> bool:
    root = repo_root()
    try:
        path.resolve().relative_to(root)
        return True
    except ValueError:
        return False


def load_objections(paths: list[Path]) -> list[tuple[str, str, str, Path]]:
    files: list[Path] = []
    for path in paths:
        if path.is_dir():
            files.extend(sorted(path.rglob("*.md")))
        elif path.is_file():
            files.append(path)
        else:
            print(f"error: not found: {path}", file=sys.stderr)
            sys.exit(2)

    if not files:
        print("error: no gate report files to read", file=sys.stderr)
        sys.exit(2)

    objections = []
    for file in files:
        for line in file.read_text(encoding="utf-8").splitlines():
            match = OBJECTION_RE.match(line.strip())
            if match:
                severity, target, description = match.groups()
                objections.append((severity, target, description, file))

    print(f"read {len(files)} report(s), {len(objections)} objection(s)", file=sys.stderr)
    return objections


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--ground-truth",
        required=True,
        type=Path,
        help="path to the ground-truth YAML — keep it OUTSIDE this repository",
    )
    parser.add_argument("--json", action="store_true", help="machine-readable output")
    parser.add_argument("reports", nargs="+", type=Path, help="gate report files or directories")
    args = parser.parse_args()

    if not args.ground_truth.is_file():
        print(f"error: ground truth not found: {args.ground_truth}", file=sys.stderr)
        return 2

    # A ground truth inside the worktree means the run is not trustworthy: an
    # agent could have read it. Say so loudly rather than printing a score that
    # looks fine.
    #
    # The boundary is the repository root, derived from THIS FILE's location —
    # not from the current directory. Using cwd meant the check silently did not
    # fire when the script was invoked from elsewhere, e.g.
    #   cd /tmp && python3 /repo/factory/benchmark/score.py --ground-truth /repo/...
    # which is exactly the case where a reassuring score is most misleading.
    if is_inside_repo(args.ground_truth):
        print(
            "\n⚠  WARNING: the ground-truth file is inside the worktree. A pipeline "
            "agent exploring the filesystem could have read it, so this score is "
            "not evidence of anything. Move it to a directory outside the repo and "
            "re-run the benchmark.\n",
            file=sys.stderr,
        )

    data = yaml.safe_load(args.ground_truth.read_text(encoding="utf-8")) or {}
    defects = data.get("defects") or []
    if not defects:
        print("error: ground truth lists no defects", file=sys.stderr)
        return 2

    # Validate the ground truth before scoring anything. A misspelled field name
    # would otherwise mark every defect MISSED and print "detection rate 0.0%",
    # which reads as "the factory caught nothing" when the truth is "the answer
    # key was malformed". Fail loudly instead.
    malformed = []
    for i, defect in enumerate(defects, 1):
        did = defect.get("id", f"#{i}")
        req = str(defect.get("requirement_id", "")).strip()
        if not req:
            malformed.append(f"{did}: no `requirement_id` field (check the spelling)")
        elif not REQUIREMENT_ID_RE.match(req):
            malformed.append(f"{did}: requirement_id {req!r} is not an FR-### or SC-### id")
    if malformed:
        print(
            "error: the ground truth cannot be scored as written. The requirement id "
            "is the join key — without it a defect can never be detected:",
            file=sys.stderr,
        )
        for problem in malformed:
            print(f"  - {problem}", file=sys.stderr)
        return 2

    objections = load_objections(args.reports)

    # Index objections by the requirement id they cite.
    blocking_by_id: dict[str, list[tuple[str, Path]]] = {}
    advisory_by_id: dict[str, list[tuple[str, Path]]] = {}
    for severity, target, description, file in objections:
        bucket = blocking_by_id if severity == "BLOCKING" else advisory_by_id
        bucket.setdefault(target, []).append((description, file))

    results = []
    for defect in defects:
        req = str(defect.get("requirement_id", "")).strip()
        if req in blocking_by_id:
            verdict, evidence = "DETECTED", blocking_by_id[req][0][0]
        elif req in advisory_by_id:
            verdict, evidence = "PARTIAL", advisory_by_id[req][0][0]
        else:
            verdict, evidence = "MISSED", ""
        results.append(
            {
                "defect_id": defect.get("id", "?"),
                "requirement_id": req,
                "defect_type": defect.get("defect_type", ""),
                "severity": defect.get("severity", ""),
                "verdict": verdict,
                "evidence": evidence,
            }
        )

    counts = {v: sum(1 for r in results if r["verdict"] == v) for v in ("DETECTED", "PARTIAL", "MISSED")}
    total = len(results)
    rate = 100.0 * counts["DETECTED"] / total if total else 0.0

    # Objections citing a requirement with no seeded defect. Not errors — the
    # spec may genuinely have other problems — but a reviewer that blocks
    # everything scores a perfect detection rate, so the number belongs next to
    # the rate rather than hidden.
    seeded_ids = {r["requirement_id"] for r in results}
    unseeded_blocking = sorted(set(blocking_by_id) - seeded_ids)

    if args.json:
        print(json.dumps(
            {
                "run": data.get("run", {}),
                "counts": counts,
                "total": total,
                "detection_rate": round(rate, 1),
                "blocking_on_unseeded_requirements": unseeded_blocking,
                "results": results,
            },
            indent=2,
            # PyYAML turns `date: 2026-08-16` into a datetime.date, which json
            # cannot serialise. str() is the right rendering for a report.
            default=str,
        ))
        return 0

    width = max((len(r["defect_id"]) for r in results), default=8)
    print(f"\n{'DEFECT'.ljust(width)}  {'REQ':<8} {'VERDICT':<9} TYPE")
    print("-" * (width + 40))
    for r in results:
        print(f"{r['defect_id'].ljust(width)}  {r['requirement_id']:<8} {r['verdict']:<9} {r['defect_type']}")
        if r["evidence"]:
            print(f"{' ' * (width + 2)}  └─ {r['evidence'][:70]}")

    print(f"\ndetected {counts['DETECTED']}/{total}   partial {counts['PARTIAL']}   missed {counts['MISSED']}")
    print(f"detection rate: {rate:.1f}%")

    if counts["PARTIAL"]:
        print(
            f"\n{counts['PARTIAL']} defect(s) drew only advisory objections. Someone "
            f"noticed and nothing stopped — that is a miss for shipping purposes."
        )
    if unseeded_blocking:
        print(
            f"\nBlocking objections on requirements with no seeded defect: "
            f"{', '.join(unseeded_blocking)}. Read the detection rate alongside "
            f"this: a reviewer that blocks everything detects everything."
        )
    return 0


if __name__ == "__main__":
    sys.exit(main())

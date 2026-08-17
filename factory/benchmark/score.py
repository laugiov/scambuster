#!/usr/bin/env python3
"""Score a factory benchmark run: did the gates catch the defects you seeded?

Reads gate reports, extracts objections in the factory's standard format, and
compares them against a ground-truth file that lists the defects you injected.

    BLOCKING|ADVISORY ; requirement ID or failing-test path ; short description

Detection rule — implemented here and stated in README.md. An objection counts as
a catch when its severity is at least the severity the defect deserved:

    seeded severity   an objection catches it at        because
    blocker           BLOCKING                          it must not ship
    major             BLOCKING                          it must not ship
    minor             ADVISORY (BLOCKING also counts)   flagging it is the right call

    DETECTED  an objection cites the requirement id at or above that bar
    PARTIAL   an objection cites it but below the bar — a blocker or major that
              drew only advisories. Someone noticed; nothing stopped.
    MISSED    no objection cites it at all

Until 2026-08-17 the rule was flatly "DETECTED iff BLOCKING", which scored a
`minor` defect correctly raised as ADVISORY as a miss — punishing the reviewers
for proportionate judgement, and pushing the profiles toward blocking on
everything, which the unseeded-blocking count exists to warn about. Run 002's
60% was measured under the old rule and is not comparable to rates produced
after it; see README.md.

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

# The objection severity a defect of each seeded severity has to draw before it
# counts as caught. A blocker or a major has to actually stop the pipeline; a
# minor is caught the moment someone says it out loud, because raising a minor
# defect as advisory is the correct response and not a failure to detect.
#
# Severity is therefore load-bearing in the ground truth, which is why an entry
# without a valid one is rejected below rather than defaulted.
EXPECTED_OBJECTION = {"blocker": "BLOCKING", "major": "BLOCKING", "minor": "ADVISORY"}
SEVERITY_RANK = {"ADVISORY": 0, "BLOCKING": 1}


def _encodable(text: str, stream) -> bool:
    encoding = getattr(stream, "encoding", None) or ""
    try:
        text.encode(encoding)
        return True
    except (LookupError, UnicodeEncodeError, TypeError):
        return False


# The report used box-drawing and warning glyphs unconditionally. On a terminal
# whose encoding cannot represent them — LANG=C, a bare CI shell, a Windows code
# page — printing raised UnicodeEncodeError and killed the run mid-table, so the
# benchmark produced one row and a traceback instead of a score. Degrade to ASCII
# rather than lose the result.
BRANCH = "└─" if _encodable("└─", sys.stdout) else "->"
WARN = "⚠ " if _encodable("⚠", sys.stderr) else "!!"

# The two constants above keep the table readable, but they are not enough on
# their own: the prose in this script is full of em dashes, and any one of them
# crashes the run just as effectively as a box-drawing character. Rather than
# hunt every non-ASCII character in every message, make the streams tolerant —
# an unrepresentable character becomes "?" and the run finishes. Losing one
# dash beats losing the score.
for _stream in (sys.stdout, sys.stderr):
    if not _encodable("—", _stream) and hasattr(_stream, "reconfigure"):
        _stream.reconfigure(errors="replace")


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
            f"\n{WARN} WARNING: the ground-truth file is inside the worktree. A pipeline "
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
        did = str(defect.get("id", "")).strip()
        if not did:
            # Previously this fell back to "?" in the DEFECT column, which made a
            # malformed entry look like a scored one and left no way to tell which
            # defect the row referred to.
            malformed.append(f"entry #{i}: no `id` field")
            did = f"#{i}"
        req = str(defect.get("requirement_id", "")).strip()
        if not req:
            malformed.append(f"{did}: no `requirement_id` field (check the spelling)")
        elif not REQUIREMENT_ID_RE.match(req):
            malformed.append(f"{did}: requirement_id {req!r} is not an FR-### or SC-### id")
        # Severity decides which objection severity counts as a catch, so a
        # missing or misspelled one would quietly change the score rather than
        # fail. Same reasoning as requirement_id above.
        sev = str(defect.get("severity", "")).strip().lower()
        if not sev:
            malformed.append(f"{did}: no `severity` field — the detection rule compares against it")
        elif sev not in EXPECTED_OBJECTION:
            malformed.append(
                f"{did}: severity {sev!r} is not one of "
                f"{', '.join(sorted(EXPECTED_OBJECTION))}"
            )
    if malformed:
        print(
            "error: the ground truth cannot be scored as written. Every defect needs "
            "an `id` to be reported against, and a `requirement_id` — the join key, "
            "without which a defect can never be detected:",
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
        severity = str(defect.get("severity", "")).strip().lower()  # validated above
        expected = EXPECTED_OBJECTION[severity]

        # The strongest objection citing this requirement is the one that decides
        # the verdict: an advisory alongside a blocking one changes nothing.
        if req in blocking_by_id:
            raised, evidence = "BLOCKING", blocking_by_id[req][0][0]
        elif req in advisory_by_id:
            raised, evidence = "ADVISORY", advisory_by_id[req][0][0]
        else:
            raised, evidence = "", ""

        if not raised:
            verdict = "MISSED"
        elif SEVERITY_RANK[raised] >= SEVERITY_RANK[expected]:
            verdict = "DETECTED"
        else:
            verdict = "PARTIAL"

        results.append(
            {
                "defect_id": str(defect["id"]).strip(),  # validated above
                "requirement_id": req,
                "defect_type": defect.get("defect_type", ""),
                "severity": severity,
                "expected_objection": expected,
                "raised_objection": raised,
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

    # Minor defects that drew a BLOCKING objection. They count as detected — the
    # reviewer found the thing — but blocking a merge over a minor defect is the
    # loudness the unseeded-blocking line warns about, arriving on a seeded
    # requirement where that line cannot see it.
    over_escalated = [
        r["defect_id"] for r in results
        if r["raised_objection"]
        and SEVERITY_RANK[r["raised_objection"]] > SEVERITY_RANK[r["expected_objection"]]
    ]

    if args.json:
        print(json.dumps(
            {
                "run": data.get("run", {}),
                "counts": counts,
                "total": total,
                "detection_rate": round(rate, 1),
                "blocking_on_unseeded_requirements": unseeded_blocking,
                "over_escalated": over_escalated,
                "results": results,
            },
            indent=2,
            # PyYAML turns `date: 2026-08-16` into a datetime.date, which json
            # cannot serialise. str() is the right rendering for a report.
            default=str,
        ))
        return 0

    width = max((len(r["defect_id"]) for r in results), default=8)
    print(f"\n{'DEFECT'.ljust(width)}  {'REQ':<8} {'SEVERITY':<8} {'NEEDED':<9} {'RAISED':<9} {'VERDICT':<9} TYPE")
    print("-" * (width + 66))
    for r in results:
        print(
            f"{r['defect_id'].ljust(width)}  {r['requirement_id']:<8} {r['severity']:<8} "
            f"{r['expected_objection']:<9} {r['raised_objection'] or '-':<9} "
            f"{r['verdict']:<9} {r['defect_type']}"
        )
        if r["evidence"]:
            print(f"{' ' * (width + 2)}  {BRANCH} {r['evidence'][:70]}")

    print(f"\ndetected {counts['DETECTED']}/{total}   partial {counts['PARTIAL']}   missed {counts['MISSED']}")
    print(f"detection rate: {rate:.1f}%")

    if counts["PARTIAL"]:
        print(
            f"\n{counts['PARTIAL']} defect(s) drew an objection weaker than they "
            f"warranted — a blocker or major raised only as advisory. Someone "
            f"noticed and nothing stopped, which is a miss for shipping purposes."
        )
    if over_escalated:
        print(
            f"\nBlocked on minor defects: {', '.join(over_escalated)}. Counted as "
            f"detected, but a gate that stops a merge over a minor defect is the "
            f"same loudness the unseeded-blocking count measures, landing on a "
            f"seeded requirement where that count cannot see it."
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

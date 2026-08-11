#!/usr/bin/env python3
"""Validate the TTP labels on the public dataset sample (Spec 004 FR-004).

The point of a labelled corpus is that a third party can score their own extractor
against it without contacting the maintainer. That only works if the labels are
mechanically sound: every code resolves against the published taxonomy, every offset
pair points at real text inside the message it claims, and every inbound message is
accounted for.

This script checks exactly that, and nothing about whether a label is *correct* —
that is a human judgement, made under the codebook, and no script can stand in for
it.

Two modes:

  (default)   structural validation. Passes on a partly-annotated file, and reports
              coverage. This is what CI runs while annotation is in progress: it
              catches a malformed label the moment it is written, without demanding
              that the whole corpus be finished first.

  --complete  additionally requires full coverage and the double-annotation floor.
              This is the release gate for publishing the labelled dataset.

Usage:
    python3 scripts/standards/validate-dataset-labels.py
    python3 scripts/standards/validate-dataset-labels.py --complete

Exit codes: 0 valid, 1 invalid, 2 could not run.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]

DEFAULT_LABELS = REPO_ROOT / "dataset" / "ttp-labels-v1.json"
DEFAULT_SAMPLE = REPO_ROOT / "scambuster-dataset-sample.json"
DEFAULT_TAXONOMY = REPO_ROOT / "backend-symfony" / "config" / "standards" / "taxonomy-v1.0.json"

# Spec 004 FR-003: at least this share of inbound messages is annotated twice,
# independently, so an agreement figure can be reported.
DOUBLE_ANNOTATION_FLOOR = 0.30


def load_json(path: Path):
    try:
        with path.open(encoding="utf-8") as handle:
            return json.load(handle)
    except FileNotFoundError:
        sys.exit(f"error: file not found: {path}")
    except json.JSONDecodeError as exc:
        sys.exit(f"error: {path} is not valid JSON: {exc}")


def inbound_bodies(sample: dict) -> dict[tuple[str, int], str]:
    """Every inbound message body, keyed by (conversation_id, message_index)."""
    bodies: dict[tuple[str, int], str] = {}
    for conversation in sample.get("conversations", []):
        conversation_id = conversation.get("conversation_id")
        for index, message in enumerate(conversation.get("messages", [])):
            if message.get("direction") != "inbound":
                continue
            bodies[(conversation_id, index)] = message.get("body") or ""
    return bodies


def validate(labels: dict, sample: dict, taxonomy: dict, require_complete: bool) -> list[str]:
    problems: list[str] = []

    valid_codes = {entry["code"] for entry in taxonomy.get("entries", []) if entry.get("active", True)}
    bodies = inbound_bodies(sample)

    if labels.get("taxonomy_version") != taxonomy.get("taxonomy_version"):
        problems.append(
            f"labels claim taxonomy_version {labels.get('taxonomy_version')!r} but the"
            f" artifact is {taxonomy.get('taxonomy_version')!r}"
        )

    seen: set[tuple[str, int]] = set()
    annotated = 0

    for entry in labels.get("messages", []):
        key = (entry.get("conversation_id"), entry.get("message_index"))
        where = f"{key[0]}#{key[1]}"

        if key in seen:
            problems.append(f"{where}: appears more than once in the label file")
            continue
        seen.add(key)

        body = bodies.get(key)

        if body is None:
            problems.append(f"{where}: labelled but is not an inbound message in the sample")
            continue

        # The offsets below are only meaningful against the exact text they were
        # measured on. A regenerated sample with shifted text would otherwise
        # produce labels that validate and point at the wrong words.
        digest = hashlib.sha256(body.encode("utf-8")).hexdigest()
        if entry.get("body_sha256") != digest:
            problems.append(
                f"{where}: body digest does not match the sample — the message text changed,"
                " so its offsets are stale and must be re-checked"
            )
            continue

        if entry.get("annotated"):
            annotated += 1

        for position, label in enumerate(entry.get("ttps", [])):
            label_where = f"{where} ttps[{position}]"
            code = label.get("code")
            start = label.get("evidence_start")
            end = label.get("evidence_end")

            if code == "UNLISTED":
                problems.append(
                    f"{label_where}: UNLISTED belongs in the companion log, not in the"
                    " label list, so it can never be mistaken for a taxonomy code"
                )
            elif code not in valid_codes:
                problems.append(f"{label_where}: {code!r} is not an active taxonomy code")

            if not isinstance(start, int) or not isinstance(end, int):
                problems.append(f"{label_where}: evidence offsets must both be integers")
                continue

            if start < 0 or end > len(body):
                problems.append(
                    f"{label_where}: offsets [{start}, {end}) fall outside the message"
                    f" body (length {len(body)})"
                )
            elif start >= end:
                problems.append(f"{label_where}: evidence_start must be before evidence_end")
            elif not body[start:end].strip():
                problems.append(f"{label_where}: the evidence span is empty or whitespace only")

        if entry.get("annotated") is False and entry.get("ttps"):
            problems.append(f"{where}: carries labels but is not marked annotated")

    missing = set(bodies) - seen
    for key in sorted(missing, key=lambda k: (str(k[0]), k[1])):
        problems.append(f"{key[0]}#{key[1]}: inbound message has no slot in the label file")

    total = len(bodies)
    double = len(labels.get("double_annotated", []))

    print(f"Inbound messages in the sample: {total}")
    print(f"Annotated: {annotated}/{total} ({annotated / total:.0%})" if total else "Annotated: 0")
    print(
        f"Double-annotated: {double}/{total} ({double / total:.0%}, floor {DOUBLE_ANNOTATION_FLOOR:.0%})"
        if total
        else "Double-annotated: 0"
    )

    if require_complete:
        if annotated < total:
            problems.append(
                f"--complete: {total - annotated} inbound message(s) are still unannotated."
                " A message with no TTP present is annotated with an empty list, not left blank."
            )
        if total and double / total < DOUBLE_ANNOTATION_FLOOR:
            problems.append(
                f"--complete: only {double / total:.0%} of messages are double-annotated,"
                f" below the {DOUBLE_ANNOTATION_FLOOR:.0%} floor — no agreement figure can be reported"
            )
        if labels.get("agreement") is None:
            problems.append("--complete: no agreement figures recorded (raw agreement and Cohen's kappa)")
        if str(labels.get("license_status", "")).startswith("proposed"):
            problems.append("--complete: the dataset licence is still proposed, not confirmed")

    return problems


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--labels", type=Path, default=DEFAULT_LABELS)
    parser.add_argument("--sample", type=Path, default=DEFAULT_SAMPLE)
    parser.add_argument("--taxonomy", type=Path, default=DEFAULT_TAXONOMY)
    parser.add_argument(
        "--complete",
        action="store_true",
        help="Also require full coverage, the double-annotation floor and a confirmed licence.",
    )
    args = parser.parse_args()

    labels = load_json(args.labels)
    sample = load_json(args.sample)
    taxonomy = load_json(args.taxonomy)

    problems = validate(labels, sample, taxonomy, args.complete)

    if problems:
        print(f"\n{args.labels.name} is INVALID ({len(problems)} problem(s)):", file=sys.stderr)
        for problem in problems[:100]:
            print(f"  - {problem}", file=sys.stderr)
        if len(problems) > 100:
            print(f"  ... and {len(problems) - 100} more", file=sys.stderr)
        return 1

    print(f"\n{args.labels.name}: valid.")
    return 0


if __name__ == "__main__":
    sys.exit(main())

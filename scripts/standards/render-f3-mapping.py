#!/usr/bin/env python3
"""Render the MITRE F3 mapping table into its document, or check that it is current.

Spec 002 FR-001. The mapping decisions live in
backend-symfony/config/standards/f3-mapping.json; the table inside
docs/standards/f3-mapping.md is generated from them, so a reviewer reading the
document and a consumer reading the STIX export can never see two different answers.

This runs on the host rather than through the Symfony console, for a plain reason:
the container bind-mounts backend-symfony/ as its project root, so docs/ — which
lives one level above it, at the repository root — does not exist inside it at all.
A console command cannot write a file it cannot see. The data-integrity half of the
mapping stays in PHP, where it belongs: F3MappingTest cross-checks the decisions
against the taxonomy seed, which is PHP.

Usage:
    python3 scripts/standards/render-f3-mapping.py            # rewrite the table
    python3 scripts/standards/render-f3-mapping.py --check    # fail if it is stale

Exit codes: 0 current (or rewritten), 1 stale or inconsistent, 2 could not run.
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]

DEFAULT_MAPPING = REPO_ROOT / "backend-symfony" / "config" / "standards" / "f3-mapping.json"
DEFAULT_TAXONOMY = REPO_ROOT / "backend-symfony" / "config" / "standards" / "taxonomy-v1.0.json"
DEFAULT_DOCUMENT = REPO_ROOT / "docs" / "standards" / "f3-mapping.md"

BEGIN_MARKER = "<!-- BEGIN GENERATED MAPPING TABLE -->"
END_MARKER = "<!-- END GENERATED MAPPING TABLE -->"

RELATIONS = ["equivalent", "narrower-than", "broader-than", "related", "none", "pending"]

# Relations that become a published STIX external reference. `broader-than` and
# `related` are excluded on purpose — see §1 of the document.
CONFIRMED_RELATIONS = ["equivalent", "narrower-than"]


def load_json(path: Path):
    try:
        with path.open(encoding="utf-8") as handle:
            return json.load(handle)
    except FileNotFoundError:
        sys.exit(f"error: file not found: {path}")
    except json.JSONDecodeError as exc:
        sys.exit(f"error: {path} is not valid JSON: {exc}")


def escape_pipes(text: str) -> str:
    return text.replace("|", "\\|")


def render(mapping: dict, taxonomy: dict) -> str:
    by_code = {entry["code"]: entry for entry in taxonomy.get("entries", [])}

    lines = [BEGIN_MARKER, ""]
    lines.append(f"**F3 version checked**: {mapping.get('framework_version') or '_not yet checked_'}")
    lines.append(f"**Date of the check**: {mapping.get('checked_on') or '_not yet checked_'}")
    lines.append("")

    if mapping.get("status") == "blocked" and mapping.get("blocked_reason"):
        lines.append(f"> **Blocked.** {mapping['blocked_reason']}")
        lines.append("")

    lines.append("| Code | Label | Phase | Relation | F3 id(s) | Rationale |")
    lines.append("|------|-------|-------|----------|----------|-----------|")

    counts = dict.fromkeys(RELATIONS, 0)
    confirmed = 0

    for entry in mapping.get("entries", []):
        code = entry.get("code", "")
        relation = entry.get("relation", "")
        ids = [i for i in entry.get("f3_ids", []) if isinstance(i, str)]
        taxonomy_entry = by_code.get(code, {})

        if relation in counts:
            counts[relation] += 1
        if relation in CONFIRMED_RELATIONS and ids:
            confirmed += 1

        lines.append(
            "| {} | {} | {} | `{}` | {} | {} |".format(
                code,
                taxonomy_entry.get("label", "_unknown code_"),
                taxonomy_entry.get("phase", "—"),
                relation,
                ", ".join(ids) if ids else "—",
                escape_pipes(entry.get("rationale", "")),
            )
        )

    lines.append("")
    lines.append("### Decision counts")
    lines.append("")
    lines.append("| Relation | Entries |")
    lines.append("|----------|---------|")
    for relation in RELATIONS:
        lines.append(f"| `{relation}` | {counts[relation]} |")

    lines.append("")
    lines.append(
        "Entries written to `external_refs` (relations {}): **{}**.".format(
            " and ".join(f"`{r}`" for r in CONFIRMED_RELATIONS), confirmed
        )
    )
    lines.append("")
    lines.append("### Reverse direction")
    lines.append("")

    reverse = mapping.get("reverse_check") or {}
    techniques = reverse.get("techniques") or []

    if not techniques:
        lines.append(
            f"Not yet recorded (status: `{reverse.get('status', 'unknown')}`). {reverse.get('note', '')}"
        )
    else:
        lines.append("| F3 id | Name | Why it is not in the ScamBuster taxonomy |")
        lines.append("|-------|------|------------------------------------------|")
        for technique in techniques:
            lines.append(
                "| {} | {} | {} |".format(
                    technique.get("id", ""),
                    technique.get("name", ""),
                    escape_pipes(technique.get("note", "")),
                )
            )

    lines.append("")
    lines.append(END_MARKER)

    return "\n".join(lines) + "\n"


def replace_block(document: str, block: str) -> str:
    begin = document.find(BEGIN_MARKER)
    end = document.find(END_MARKER)

    if begin == -1 or end == -1 or end < begin:
        sys.exit(
            f"error: the mapping document must contain the {BEGIN_MARKER} and"
            f" {END_MARKER} markers, in that order."
        )

    return document[:begin] + block + document[end + len(END_MARKER) + 1 :]


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--mapping", type=Path, default=DEFAULT_MAPPING)
    parser.add_argument("--taxonomy", type=Path, default=DEFAULT_TAXONOMY)
    parser.add_argument("--document", type=Path, default=DEFAULT_DOCUMENT)
    parser.add_argument(
        "--check",
        action="store_true",
        help="Fail when the document is stale instead of rewriting it.",
    )
    args = parser.parse_args()

    mapping = load_json(args.mapping)
    taxonomy = load_json(args.taxonomy)

    try:
        document = args.document.read_text(encoding="utf-8")
    except FileNotFoundError:
        sys.exit(f"error: mapping document not found: {args.document}")

    regenerated = replace_block(document, render(mapping, taxonomy))

    if regenerated == document:
        print(f"{args.document.name}: up to date.")
        return 0

    if args.check:
        print(
            f"{args.document.name} is STALE. Run:\n"
            "  python3 scripts/standards/render-f3-mapping.py\n"
            "and commit the result. Edit the JSON, never the generated table.",
            file=sys.stderr,
        )
        return 1

    args.document.write_text(regenerated, encoding="utf-8")
    print(f"{args.document.name}: regenerated.")
    return 0


if __name__ == "__main__":
    sys.exit(main())

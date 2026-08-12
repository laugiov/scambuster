#!/usr/bin/env python3
"""Validate the generated TTP taxonomy artifact against its JSON Schema.

The PHP side already guarantees the artifact matches the canonical seed and is
byte-stable; this script is the independent half — it checks the file against the
committed schema with a real JSON Schema implementation rather than the project's
own code.

That separation is the point. A generator validating its own output with its own
assumptions proves very little. A third party reading this repository validates the
artifact exactly the way this script does, with a library neither side controls.

Usage:
    python3 scripts/standards/validate-taxonomy-artifact.py
    python3 scripts/standards/validate-taxonomy-artifact.py --artifact path/to/file.json

Exit codes: 0 valid, 1 invalid, 2 could not run (missing file or missing dependency).
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
STANDARDS_DIR = REPO_ROOT / "backend-symfony" / "config" / "standards"
DEFAULT_SCHEMA = STANDARDS_DIR / "taxonomy.schema.json"
DEFAULT_ARTIFACT = STANDARDS_DIR / "taxonomy-v1.0.json"


# Exit code for "could not run", as opposed to 1 for "ran and found problems".
# CI needs to tell a broken environment from a real finding: the first is a build
# to fix, the second is a change to fix, and treating them alike trains people to
# ignore both.
CANNOT_RUN = 2


def load_json(path: Path) -> object:
    try:
        with path.open(encoding="utf-8") as handle:
            return json.load(handle)
    except FileNotFoundError:
        print(f"error: file not found: {path}", file=sys.stderr)
        sys.exit(CANNOT_RUN)
    except json.JSONDecodeError as exc:
        print(f"error: {path} is not valid JSON: {exc}", file=sys.stderr)
        sys.exit(CANNOT_RUN)


def semantic_checks(artifact: dict) -> list[str]:
    """Constraints the schema vocabulary cannot express.

    JSON Schema can say "phase is a string"; it cannot say "phase is one of the
    values listed elsewhere in this same document". These are the cross-field rules
    that make the artifact internally coherent.
    """
    problems: list[str] = []
    entries = artifact.get("entries", [])
    phases = set(artifact.get("phases", []))

    for entry in entries:
        code = entry.get("code", "?")
        if entry.get("phase") not in phases:
            problems.append(
                f"{code}: phase {entry.get('phase')!r} is not in the declared phases list"
            )

    declared = artifact.get("entry_count")
    if declared != len(entries):
        problems.append(f"entry_count is {declared} but the file carries {len(entries)} entries")

    codes = [entry.get("code") for entry in entries]
    if len(set(codes)) != len(codes):
        problems.append("taxonomy codes are not unique")

    labels = [entry.get("label") for entry in entries]
    if len(set(labels)) != len(labels):
        problems.append("taxonomy labels are not unique")

    # A collision here would make two taxonomy entries the same object to every
    # STIX consumer downstream.
    stix_ids = [entry.get("stix_id") for entry in entries]
    if len(set(stix_ids)) != len(stix_ids):
        problems.append("STIX attack-pattern ids are not unique")

    return problems


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--schema", type=Path, default=DEFAULT_SCHEMA)
    parser.add_argument("--artifact", type=Path, default=DEFAULT_ARTIFACT)
    args = parser.parse_args()

    try:
        from jsonschema import Draft202012Validator
    except ImportError:
        print("error: the jsonschema package is required (pip install jsonschema)", file=sys.stderr)
        return CANNOT_RUN

    schema = load_json(args.schema)
    artifact = load_json(args.artifact)

    Draft202012Validator.check_schema(schema)
    validator = Draft202012Validator(schema)

    errors = sorted(validator.iter_errors(artifact), key=lambda error: list(error.path))
    problems = [
        f"{'/'.join(str(part) for part in error.path) or '<root>'}: {error.message}"
        for error in errors
    ]

    if not problems and isinstance(artifact, dict):
        problems.extend(semantic_checks(artifact))

    if problems:
        print(f"{args.artifact.name} is INVALID against {args.schema.name}:", file=sys.stderr)
        for problem in problems:
            print(f"  - {problem}", file=sys.stderr)
        return 1

    entry_count = artifact.get("entry_count") if isinstance(artifact, dict) else "?"
    version = artifact.get("taxonomy_version") if isinstance(artifact, dict) else "?"
    print(f"{args.artifact.name}: valid — taxonomy v{version}, {entry_count} entries.")
    return 0


if __name__ == "__main__":
    sys.exit(main())

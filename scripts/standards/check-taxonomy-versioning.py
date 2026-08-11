#!/usr/bin/env python3
"""Fail when the TTP taxonomy content changed without a version bump and a changelog entry.

Spec 003 FR-006, Constitution VI.

The taxonomy is a published contract. A consumer pins `taxonomy_version` and expects
that a given version always means the same definitions. A silent content change
breaks that promise without anyone noticing, which is exactly the failure this check
exists to make loud.

"Content changed" is decided by comparing the generated artifact's entries between
the merge base and HEAD — not by which files the diff touched. That distinction
matters: moving the seed constant to another class, reformatting it, or renaming a
file are all refactors that change nothing a consumer can observe, and a check that
demanded a MAJOR bump for them would be noise people learn to ignore. What counts is
whether any code, label, definition, phase, example, stimulus affinity, external
reference, active flag or STIX id came out different.

The complementary check — that the committed artifact actually matches the seed — is
`scambuster:ttp:taxonomy-export --check`, which CI runs separately. Together they
close the loop: the artifact cannot drift from the seed, and the artifact cannot
change without a version.

Usage:
    python3 scripts/standards/check-taxonomy-versioning.py [--base-ref origin/main]

Exit codes: 0 no change or properly versioned, 1 unversioned change, 2 could not run.
"""

from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]

ARTIFACT_GLOB = "backend-symfony/config/standards/taxonomy-v*.json"
VERSION_FILE = "backend-symfony/src/Domain/Communication/Ttp.php"
CHANGELOG = "CHANGELOG.md"

VERSION_PATTERN = re.compile(r"TAXONOMY_VERSION\s*=\s*'([^']+)'")


def git(*args: str) -> str:
    result = subprocess.run(
        ["git", *args],
        cwd=REPO_ROOT,
        capture_output=True,
        text=True,
        check=False,
    )
    if result.returncode != 0:
        raise RuntimeError(result.stderr.strip() or f"git {' '.join(args)} failed")
    return result.stdout


def git_show(ref: str, path: str) -> str | None:
    """File contents at a ref, or None when the file did not exist there."""
    result = subprocess.run(
        ["git", "show", f"{ref}:{path}"],
        cwd=REPO_ROOT,
        capture_output=True,
        text=True,
        check=False,
    )
    return result.stdout if result.returncode == 0 else None


def artifact_paths(ref: str) -> list[str]:
    listing = git(
        "ls-tree", "-r", "--name-only", ref, "--", "backend-symfony/config/standards/"
    )
    return sorted(
        path
        for path in listing.splitlines()
        if re.fullmatch(r"backend-symfony/config/standards/taxonomy-v[^/]+\.json", path)
    )


def taxonomy_content(ref: str) -> dict[str, object] | None:
    """The observable taxonomy at a ref: every artifact's entries, keyed by file.

    `taxonomy_version` itself is excluded — a version bump is the response to a
    content change, not a content change in its own right, and including it would
    make every bump look like one more thing to bump.
    """
    paths = artifact_paths(ref)
    if not paths:
        return None

    content: dict[str, object] = {}
    for path in paths:
        raw = git_show(ref, path)
        if raw is None:
            continue
        try:
            document = json.loads(raw)
        except json.JSONDecodeError:
            print(f"error: {path} at {ref} is not valid JSON", file=sys.stderr)
            sys.exit(2)
        content[path] = {
            "kill_chain_name": document.get("kill_chain_name"),
            "phases": document.get("phases"),
            "entries": document.get("entries"),
        }
    return content


def describe_changes(before: dict, after: dict) -> list[str]:
    """Human-readable summary of what moved, so the failure message is actionable."""
    notes: list[str] = []

    for path in sorted(set(before) | set(after)):
        old = before.get(path)
        new = after.get(path)

        if old is None:
            notes.append(f"{Path(path).name}: new taxonomy artifact")
            continue
        if new is None:
            notes.append(f"{Path(path).name}: artifact removed")
            continue
        if old == new:
            continue

        old_entries = {entry["code"]: entry for entry in old.get("entries") or []}
        new_entries = {entry["code"]: entry for entry in new.get("entries") or []}

        added = sorted(set(new_entries) - set(old_entries))
        removed = sorted(set(old_entries) - set(new_entries))
        modified = sorted(
            code
            for code in set(old_entries) & set(new_entries)
            if old_entries[code] != new_entries[code]
        )

        if added:
            notes.append(f"{Path(path).name}: codes added — {', '.join(added)} (MINOR at least)")
        if removed:
            notes.append(
                f"{Path(path).name}: codes REMOVED — {', '.join(removed)}."
                " Codes are deprecated with active=false, never deleted (Constitution VI)"
            )
        if modified:
            notes.append(f"{Path(path).name}: codes modified — {', '.join(modified)}")
        if old.get("kill_chain_name") != new.get("kill_chain_name"):
            notes.append(
                f"{Path(path).name}: kill_chain_name changed — this is breaking for every"
                " STIX consumer and is a MAJOR change"
            )
        if old.get("phases") != new.get("phases"):
            notes.append(f"{Path(path).name}: kill-chain phases changed")
        if not (added or removed or modified):
            notes.append(f"{Path(path).name}: content changed")

    return notes


def read_version(ref: str | None) -> str | None:
    raw = (
        (REPO_ROOT / VERSION_FILE).read_text(encoding="utf-8")
        if ref is None
        else git_show(ref, VERSION_FILE)
    )
    if raw is None:
        return None
    match = VERSION_PATTERN.search(raw)
    return match.group(1) if match else None


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--base-ref",
        default="origin/main",
        help="Branch or ref to compare against (default: origin/main).",
    )
    args = parser.parse_args()

    try:
        merge_base = git("merge-base", args.base_ref, "HEAD").strip()
    except RuntimeError as exc:
        print(f"error: {exc}", file=sys.stderr)
        print(f"hint: fetch {args.base_ref} first, or pass --base-ref.", file=sys.stderr)
        return 2

    before = taxonomy_content(merge_base)
    after = taxonomy_content("HEAD")

    if before is None:
        print("No taxonomy artifact at the merge base — nothing to compare against.")
        return 0

    if before == after:
        print("No taxonomy content change in this diff.")
        return 0

    print("Taxonomy content changed in this diff:")
    for note in describe_changes(before, after or {}):
        print(f"  - {note}")

    problems: list[str] = []

    base_version = read_version(merge_base)
    head_version = read_version(None)

    if head_version is None:
        print(f"error: could not read TAXONOMY_VERSION from {VERSION_FILE}", file=sys.stderr)
        return 2

    if base_version == head_version:
        problems.append(
            f"TAXONOMY_VERSION is still '{head_version}'. Bump it in {VERSION_FILE}."
        )
    else:
        print(f"\nTAXONOMY_VERSION: {base_version or '<absent>'} -> {head_version}")

    changed_files = git("diff", "--name-only", merge_base, "HEAD").splitlines()
    if CHANGELOG not in changed_files:
        problems.append(f"{CHANGELOG} was not updated. Record what changed and why.")

    if not problems:
        print("\nOK: the taxonomy change carries a version bump and a changelog entry.")
        return 0

    print("\nFAIL: unversioned taxonomy change.")
    for problem in problems:
        print(f"  - {problem}")
    print(
        "\nThe taxonomy follows semantic versioning:\n"
        "  MAJOR  meaning change, or deprecation of a code (deprecate with active=false, never delete)\n"
        "  MINOR  new codes or new phases\n"
        "  PATCH  wording fixes that change no meaning, and external_refs additions\n"
        "\nSee docs/standards/taxonomy-versioning.md.\n"
        "After bumping, regenerate: php bin/console scambuster:ttp:taxonomy-export"
    )
    return 1


if __name__ == "__main__":
    sys.exit(main())

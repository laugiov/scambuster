# SEC-001 run — friction note

What the pipeline cost, and what it caught. Written as the run goes, not
reconstructed at the end. Entries are appended in order; nothing is edited out
once the next stage disagrees with it.

Format: **[stage] verdict — what happened.** Verdict is one of *overhead*
(the step produced nothing the run would not have had anyway), *caught*
(the step changed the output), or *gap* (the pipeline had nothing to say and
should have).

---

### [a] caught — the root-cause document forced the second instance into scope

The register row for SEC-001 cites one location:
`PreprodCopyService.php:15`. Writing the **Entry points** section as a list of
"every path", rather than as a sentence about the reported line, is what turned
up `docker-compose.yml:145` — the same credential, same commit, never flagged,
outside the Semgrep rule's `paths.include`. Without that section I would have
scoped a fix to one file and shipped it, and the finding would have stayed half
open with a green pipeline behind it.

This is the honest answer to "was the root-cause gate ceremony on a fix this
small". **It was not.** The fix is a handful of lines; the analysis is what
established that the fix is in two files rather than one, and that removing the
literal does not stop the credential reaching the dev database's error log. Both
of those change the shape of the fix, and both were found by writing the
document rather than by reading the code — I had read the code first.

### [a] overhead — the "three sections and nothing else" rule fought the brief

The pipeline says the root-cause document has three sections and nothing else.
The maintainer's instruction for this run says the analysis will be read for what
is *missing* from it. Those pull in opposite directions: the natural home for
"here is what I could not establish and how it would move the severity" is a
fourth section, and there is no fourth section.

Resolved by putting the absence content **inside** each of the three sections —
each ends with what it could not close. That is arguably better writing than a
segregated caveats section, so this is mild overhead rather than a defect. But
the rule is stated as a hard constraint and I spent real time deciding whether
following it was allowed to make the document worse. Worth a sentence in the
pipeline doc saying the three sections may carry their own limits, so the next
run does not re-derive it.

### [a] gap — no storage convention for a security run's artifacts

`docs/factory/templates/gate-report.md:10-11` names a path for the feature
pipeline and a path for the bug pipeline. The security pipeline has no `specs/`
directory and is named in neither line. There is no wrong answer available, only
an unmade decision, so this run invented `factory/security/<SEC-###>/` and said
so in its README instead of silently adopting the bug pipeline's path.

Small, but this is exactly the kind of thing that is cheap now and is three
incompatible layouts in six months.

### [a] overhead — the disclosure rule is unresolvable as written for this finding

"Nothing about an unfixed vulnerability goes into a public issue, a public PR
body, or a commit message until the maintainer agrees." The vulnerability is a
credential that has been in a public repository since the initial release, and
these run notes are files in that same public repository. There is no
confidential channel here; the gate report *is* a committed file. The rule is
right for a finding that is not yet public and has nothing to say about one that
was published on 2026-08-05 with the initial public release.

Followed as far as it goes — no exploitation recipe, and the commit message
describes the artifact rather than the flaw — but this cost more deliberation
than it was worth, and the pipeline should say what it means for a
publicly-disclosed finding.

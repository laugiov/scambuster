# Customizing LLM Prompts (without editing code)

ScamBuster's behaviour is driven by a set of LLM prompts. Different deployments have
different needs -- a CTI team, a bank fraud unit, a national CERT and an enterprise SOC
do not run the same scam mix, speak the same language, or extract the same intelligence.
This guide explains how to adapt those prompts to **your** context **without editing any
PHP**, and why it is safe to do so.

> **Status.** This capability rolls out prompt-by-prompt. The list of currently
> overridable prompts is in [What you can override today](#what-you-can-override-today).
> The resolution mechanism below is stable and applies to every prompt as it becomes
> configurable.

## Why this exists

- **Fit your context.** Tune the voice, the intelligence you prioritise, and the
  scenarios your personas play, to match your sector and threat landscape.
- **No fork, no redeploy of code.** Overrides are data you own. A fresh install works out
  of the box with zero configuration.
- **Safe by design.** Customization only steers *generative* prompts. It can never relax
  the deterministic safety guardrails (see [The safety line](#the-safety-line-you-cannot-cross)).

## Two ways to customize

You can override a prompt in either of two ways -- pick whichever fits your workflow.

### A) The admin UI -- *Prompt Customization* (recommended)

In the app, open **Personas → Prompt Customization**. For each overridable prompt you can:

- **See the shipped default** read-only ("start from the default" pre-fills the editor
  with it), so you always know what you are replacing;
- **Edit** the prompt for your context, with a **glossary** explaining every
  `{{PLACEHOLDER}}` and live validation that you kept the required ones;
- **Enable / disable** your override, or **revert to the default** in one click;
- see the status at a glance (*Active*, *Disabled*, *Rejected*, *Shipped default*).

UI overrides are stored in the database and take effect **without a restart**. Editing is
restricted to accounts with the `config:write` permission, and every change is recorded in
the audit log.

### B) Git-tracked files (configuration-as-code)

If you prefer to version your prompts alongside the rest of your configuration, drop a
file in the tracked directory:

```
backend-symfony/config/scambuster/prompts/<key>.txt
```

```bash
cd backend-symfony/config/scambuster/prompts
$EDITOR contextual_enrichment.txt      # <key>.txt — see README for the current default + tokens
git add contextual_enrichment.txt
git commit -m "Customize IOC enrichment prompt for our threat mix"
```

Restart the backend to pick up a file change. To revert, delete the file (or `git revert`)
to fall back to the shipped default. Each prompt's required placeholders are listed in
`config/scambuster/prompts/README.md`.

## How resolution works (precedence)

When ScamBuster builds a prompt with key `<key>`, it resolves it in this order and uses
the **first present and valid** source:

1. **Database override** (from the admin UI), if enabled and valid;
2. **File override** `config/scambuster/prompts/<key>.txt`, if present and valid;
3. otherwise the **shipped default** that ships with ScamBuster.

So a UI override wins over a file override, which wins over the shipped default. If you use
only files, the UI layer is simply empty and step 1 is skipped.

Resolution is **fail-safe**. An override that is absent, empty, unreadable, or invalid
silently degrades to the next source (a warning is logged). A broken override can only
lose *your* edit -- it can never break the reply pipeline.

### Placeholders

Prompts contain `{{PLACEHOLDER}}` tokens that ScamBuster fills in at runtime (the scam
type, the conversation so far, and so on). You may restructure the prose freely, but you
**must keep every required placeholder** for a given prompt. If your override drops a
required placeholder, it is rejected (shown as *Rejected* in the UI) and the next source
is used instead -- this prevents an edit from silently blinding the model to the data it
needs. The admin UI lists each token with its meaning and flags a missing one before you
save.

## Two layers: persona character vs shared style

A honeypot reply is built from **two layers**, customized in two different places:

1. **The persona's character** -- *who* the persona is (their backstory, voice, situation). This
   lives on the **Personas** screen, one description per persona (e.g. a lonely widow, an
   overwhelmed admin, a thrilled retiree). Edit a persona there to change that persona alone.
2. **The shared style & rules** -- *how* every persona writes an email: greeting, tone,
   name-handling, signing, anti-repetition. This is the `persona_style_rules` prompt on this
   screen. It is **shared by all personas** and applied **on top of** each one's character.

So the two are complementary, not competing: the persona description is the character, and
`persona_style_rules` is the house writing style layered over it. Editing `persona_style_rules`
changes the style for **every** persona at once; editing a persona's description changes only
that persona. (A small set of CORE rules -- anti-unmask, stay-on-email, no out-of-band channel,
careful-buyer, language fidelity -- is always applied and is never editable from either place.)

## Multilingual by design

Scammers operate in many languages, so ScamBuster does too. Detection rules (threats,
authority impersonation, urgency cues), persona system prompts, and strategy guidance ship
with non-English content **on purpose** -- it is operational data that lets the honeypot
engage scammers in their own language, and it is exactly the kind of content you are meant
to adapt here.

The codebase itself (identifiers, comments, logs) is English; the non-English strings you
see in seed data and prompts are intentional, not untranslated code. Add or adapt languages
by editing the persona and detection seed data -- no code change. Language fidelity (replying
in the language the scammer wrote in) is one of the CORE rules enforced in code, so an
override can add a language but can never make a persona answer in the wrong one.

## What you can override today

| Key | Prompt | Purpose |
|-----|--------|---------|
| `persona_style_rules` | Persona voice & style rules (reply generation) | How your personas *write* on every reply -- greeting, tone, name-handling, signing, anti-repetition. **Shared by every persona and layered on top of each persona's own description** (see [Two layers](#two-layers-persona-character-vs-shared-style) below). This is the first prompt that shapes the actual replies, so the **Validate this prompt** button is available for it. The safety rules (never leak an out-of-band channel, stay on the email thread, careful-buyer pushback, language fidelity) are enforced separately in code and can never be relaxed by an override. No placeholders. |
| `conversation_director_strategy` | Conversation Director -- strategy (reply generation) | How the Director (the "brain" that plans each turn) *infers this scam's goal* and *varies each reply's shape* so messages don't read alike. Drives every reply, so the **Validate this prompt** button is available. The JSON output contract the pipeline parses, the anti-unmask / never-re-ask rule, hostile-scammer detection and language fidelity stay locked in code and are never editable. No placeholders. |
| `conversation_director_tone` | Conversation Director -- tone palette (reply generation) | The emotional register the Director recommends as the exchange progresses (worried → suspicious → reassured → confident → annoyed → direct). Only the palette is editable; the output contract and every safety rule stay locked in code. **Validate this prompt** available. No placeholders. |
| `contextual_enrichment` | IOC-context semantic enrichment | How each observed IOC is described and classified when building the enriched context bundle. Runs during IOC extraction. |
| `reward_judge` | Outcome-scoring rubric | Defines what counts as "a good outcome" for a finished conversation. This score re-points the persona-selection bandit toward the outcomes **you** care about (e.g. obtaining a cash-out channel, actor attribution, or fresh infrastructure). Static rubric -- no placeholders. |

More prompts become overridable over time; this table, the admin UI, and the in-repo
README are kept in step with what is actually available.

### Check what is active

A read-only diagnostic command reports which source each prompt resolves to (and prints an
active override), matching exactly what the runtime uses:

```bash
docker compose exec backend-dev php bin/console scambuster:prompt:diag
docker compose exec backend-dev php bin/console scambuster:prompt:diag reward_judge
```

## Tunable settings

Beyond prompt text, a small set of numeric settings live in a git-versioned file,
`config/scambuster/scambuster.yaml`. Shipped defaults are in
`config/scambuster/scambuster.defaults.yaml`; copy a key into `scambuster.yaml` to
override it. Zero configuration works out of the box (the defaults apply). A malformed
settings file fails the container build deliberately -- catch it before deploying.

```yaml
# config/scambuster/scambuster.yaml
parameters:
    scambuster.reward.llm_weight: 0.85   # default 0.7
```

| Setting | Default | Effect |
|---------|---------|--------|
| `scambuster.reward.llm_weight` | `0.7` | How much the `reward_judge` outcome score counts (0.0–1.0) versus the mechanical metrics (duration, IOC counts) when the persona-selection bandit learns. Raise it to trust your outcome rubric more; lower it to lean on the mechanical metrics. |

## The safety line you cannot cross

Prompt customization steers **generative** behaviour only. It can **never**:

- make the honeypot emit a real payment instrument, an out-of-band contact channel, a
  threat, or impersonate an authority;
- weaken or disable any of the deterministic safety checks.

Those guarantees are enforced in code by dedicated guards that run on every generated
reply, **independently of any prompt**. No override -- whether from the UI or a file -- can
reach them. This is what makes it safe to hand the generative prompts to operators: you can
freely change *how* a persona speaks, but not *what the system is structurally forbidden
from doing*.

## Good practice

- **Keep required placeholders.** They are the data contract; dropping one falls back to
  the next source. The UI validates this for you.
- **Start from the shipped default** and adapt it, rather than writing from scratch -- you
  inherit the safety-aware structure and every placeholder.
- **Change one prompt at a time** and observe the effect before the next change.
- **Pick one layer per prompt.** A UI override shadows a file override for the same key; do
  not maintain both for the same prompt or the file will look ignored.
- **Treat file overrides as configuration-as-code** -- review, version and roll them back
  with git. UI overrides are captured in the audit log instead.

## Validating a prompt change (regression gate)

Because a prompt drives an LLM, the effect of an edit is not obvious from reading it. ScamBuster
ships a **regression gate** so you can check a candidate prompt against a frozen reference
*before* it reaches production. It runs the real reply pipeline over a fixed set of scam
scenarios, scores every generated reply, and compares the result to a committed baseline.

### From the admin UI (recommended)

On the **Prompt Customization** page, each reply-path prompt has a **"Validate this prompt"**
button. Type your candidate prompt in the editor and click it -- you do **not** need to save or
activate the override first, and you never touch a command line.

> The button appears only where the canary can actually produce a verdict: a reply-path prompt
> **and** a live model provider in this deployment (OpenAI / Anthropic / Ollama with its
> credentials -- not the `mock` provider, and not a missing key). On a mock or keyless deployment
> (e.g. the public demo) it is hidden with a short "validation unavailable" note, since a job
> there could only hang or fail. The endpoint enforces the same rule server-side: a direct API
> call to request a validation on such a deployment is refused with `503 Service Unavailable`
> rather than enqueuing a job that could never complete.

- The check runs **in the background** (it makes real model calls and can take a while). You can
  refresh or navigate away and come back: a running validation is re-attached and the prompt it is
  checking is restored into the editor -- even if you never saved it. A finished verdict re-appears
  on load as long as the saved prompt still matches what was validated. (A verdict for a prompt you
  never saved is not restored once the run has finished -- save the prompt if you want its verdict to
  persist across a reload.)
- When it finishes you get a clear verdict: **no regression** (your prompt stays within tolerance
  of the baseline) or a **list of regressions** to review -- each naming the signal, the baseline
  vs your candidate's rate, and why -- *before* you decide to activate the override.
- The candidate is used **only** for this check; it is never saved or activated as a side effect.

The button is `config:write`-gated and every request is audit-logged. (Background validation is
served by the `canary-worker` container, which sits behind the `canary` Compose profile --
start it with `docker compose up -d canary-worker` before clicking "Validate", or drain one
job on demand with `make guard-canary-work`.)

### From the command line (contributors / CI)

The same gate runs offline from the CLI, exiting non-zero on any regression -- so it drops
straight into CI or a pre-merge step:

```bash
# Real-LLM smoke over the scenario set, then compare to the baseline.
# Uses an LLM and takes a few minutes — run it deliberately, not on every save.
make guard
```

### What it checks

The gate flags a candidate when, versus the baseline, it:

- lets a **concrete safety leak** slip through more often -- the persona *giving out* a crypto
  wallet or an out-of-band contact channel (a handle, a link, a phone number), an
  out-of-band-length reply, a language mismatch, or any hint that the sender is automated;
- changes the **fallback rate** beyond a small tolerance -- in *either* direction (a sudden drop
  can mean a guard was inadvertently weakened, not that quality improved);
- produced **too little to judge** -- an empty, errored, or near-empty run fails closed rather
  than passing on no evidence.

It deliberately does **not** treat the honeypot's own job as a regression: *asking* the scammer
for their payment details (IBAN, wallet) or *naming* a platform to elicit their handle is desired
intelligence-gathering, not a leak -- only a concrete instrument or channel the persona **gives
out** counts. This keeps the gate high-signal so its verdicts stay worth reading.

Stable behaviour stays green: the check is a delta detector with a noise-tolerance band, so
normal run-to-run variation does not trip it. The comparison is deterministic and offline -- no
LLM -- and the baseline is integrity-checked before use, so a hand-edited baseline is rejected.

To run the gate automatically when a push changes prompt-affecting files, install the opt-in
git hook (it prints a reminder by default; set `GUARD_ON_PUSH=1` to make it block the push on a
regression):

```bash
make guard-hook-install
```

> This gate is a **quality** check on generative behaviour. It is *in addition to* -- never a
> replacement for -- the deterministic guards described next, which enforce the safety line on
> every reply regardless of any prompt.

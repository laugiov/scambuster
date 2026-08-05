# Operator prompt overrides

Drop a `<key>.txt` file here to override a shipped LLM prompt **without editing any
PHP**. If the file is absent, the shipped inline default is used, so a fresh install
works out of the box with zero files here.

## How resolution works

For a given prompt `key`, `PromptProvider` reads `<key>.txt` from this directory:

- **absent / empty / unreadable** → the shipped inline default runs (unchanged behaviour);
- **present but missing a required `{{PLACEHOLDER}}`** → it is rejected and the shipped
  default runs instead (a warning is logged). This prevents an edit from silently
  dropping the data the prompt depends on;
- **present and valid** → your file is used, with `{{PLACEHOLDER}}` tokens substituted.

A broken override can only lose your own edit — it can never break the reply pipeline.

## Versioning

This directory is tracked by git. Your overrides are versioned like any other file:
`git log`/`git blame` for history, `git revert` to roll back. There is no database and
no admin endpoint for prompts.

## Safety boundary

These overrides steer **generative** prompts only. They can never relax the
deterministic safety gate: the honeypot still refuses to emit real payment details,
out-of-band contact channels, threats, or authority impersonation regardless of any
override here. Those rules live in code (PolicyGuard / PaymentInstigationGuard) and are
not configurable.

## Available keys

| Key | Prompt |
|-----|--------|
| `contextual_enrichment` | IOC-context semantic enrichment (required tokens: `{{SCAM_TYPE}}`, `{{PERSONA_CODE}}`, `{{REVELATION_TURN}}`, `{{TOTAL_TURNS}}`, `{{IOC_TYPES}}`, `{{PREVIOUS_INBOUND}}`, `{{STIMULUS_MESSAGE}}`, `{{REVELATION_MESSAGE}}`) |
| `reward_judge` | Outcome-scoring rubric that defines what "a good outcome" is (drives the persona-selection bandit). No required tokens — it is a static rubric. |

More keys are added as further prompts become overridable.

## Tunable settings (scalars)

Numeric/boolean settings live in `config/scambuster/scambuster.yaml` (git-versioned,
optional). Shipped defaults are in `config/scambuster/scambuster.defaults.yaml` — do not
edit that file; copy the key you want to change into `scambuster.yaml`, which overrides it.
A malformed settings file fails the container build on purpose, so catch it before deploy.

```yaml
# config/scambuster/scambuster.yaml
parameters:
    scambuster.reward.llm_weight: 0.85   # default 0.7
```

| Setting | Default | Effect |
|---------|---------|--------|
| `scambuster.reward.llm_weight` | `0.7` | Weight (0.0–1.0) of the LLM outcome score in the reward blend; the remainder is the mechanical metric reward. Higher trusts the `reward_judge` rubric more; lower leans on duration/IOC metrics. |

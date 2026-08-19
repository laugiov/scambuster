# Data Processing Agreements (DPAs)

ScamBuster sends scammer-supplied message text to an LLM for inference (classification,
reply generation, IOC extraction). Where that LLM is a third-party cloud provider, the
operator (controller) must have a **Data Processing Agreement** with it.

## Required DPAs

| Provider | When it applies | Action |
|----------|-----------------|--------|
| **OpenAI** | `LLM_PROVIDER=openai` | Accept the OpenAI Business/API DPA (openai.com/policies/data-processing-addendum). API data is **not** used for training by default; confirm and record. |
| **Anthropic** | `LLM_PROVIDER=anthropic` | Accept Anthropic's Commercial Terms + DPA. |
| Hosting provider | Always | Operator's standard infrastructure DPA. |

## How to avoid an LLM DPA entirely

ScamBuster supports fully-local inference -- **no scammer content leaves the operator's
infrastructure**, so no LLM sub-processor and no international transfer:

- `LLM_PROVIDER=ollama` -- run an on-prem model (see the LLM provider docs).
- `LLM_PROVIDER=mock` -- deterministic synthetic replies (demo / no inference).

For sensitive deployments (CERT/government), **Ollama is the recommended posture**.

## Record
The operator records, per environment: provider in use, DPA reference/date, whether training
is disabled, and the transfer mechanism (if cross-border). Keep alongside the Article 30 record.

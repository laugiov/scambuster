# 035 — Fix Language Detection at Ingestion

## Problem

All replies are generated in Portuguese, Spanish, or Italian instead of English. The 6 test emails were all in English but ScamBuster replied in the wrong language every time.

### Root cause

`IngestHandler.php` line ~494 hardcodes `'fr'` (French) as the language for every ingested message:

```php
$messageEntity = new Message(
    // ...
    'fr',  // ← HARDCODED FRENCH
    // ...
);
```

The `LanguageDetector` class exists and works correctly (trigram frequency analysis for 7 languages), but it's only called during reply generation in `ReplyHandler.detectLanguageFromContext()`. By then, the stored `lang_detect` field is already wrong, and the PromptBuilder uses the detected language (from the actual text) — but something in the chain still reads the stored value.

Additionally, `BasePromptRules` injects `This person writes entirely in {detectedLanguage}` — if the detected language is wrong, all replies will be in the wrong language.

## Solution

### A. Detect language at ingestion time

In `IngestHandler`, after extracting the message body, call `LanguageDetector::detect()` on the body text and store the correct language:

```php
$detectedLang = 'en'; // default
if ($this->languageDetector !== null && !empty($bodyText)) {
    $detectedLang = $this->languageDetector->detect($bodyText);
}

$messageEntity = new Message(
    // ...
    $detectedLang,  // ← Detected, not hardcoded
    // ...
);
```

### B. Fallback to English

If the body text is too short for reliable detection (< 50 characters), default to English. The LanguageDetector should have a minimum confidence threshold.

### C. Fix the hardcoded 'fr'

This is a single-line fix but has cascade effects — all future messages will have correct language, which means the ReplyHandler and PromptBuilder will use the right language.

## Files to modify

| File | Change |
|------|--------|
| `src/Application/Communication/IngestHandler.php` | Replace hardcoded 'fr' with LanguageDetector call |
| `tests/Integration/Communication/IngestHandlerTest.php` | Test that English emails get lang_detect='en' |
| `tests/Unit/Application/LLM/LanguageDetectorTest.php` | Verify detector accuracy on test emails |

## Acceptance criteria

1. English emails get `lang_detect = 'en'` in the message record
2. Replies to English emails are in English
3. French emails still get `lang_detect = 'fr'`
4. Short/ambiguous text defaults to 'en'
5. `make test` passes
6. CI green

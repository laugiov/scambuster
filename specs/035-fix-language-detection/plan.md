# 035 — Plan: Fix Language Detection

## Step 1: Fix the hardcoded 'fr' in IngestHandler

1. Find the exact line in `IngestHandler.php` where `'fr'` is hardcoded
2. Inject `LanguageDetector` into IngestHandler constructor
3. Call `detect($bodyText)` on the inbound message body
4. If body is too short (< 50 chars) or detector is null, default to 'en'
5. Store detected language in message entity

## Step 2: Add minimum confidence threshold to LanguageDetector

1. Read `LanguageDetector.php` to understand current implementation
2. If the detection confidence is below a threshold (e.g., score difference between top 2 languages < 10%), default to 'en'
3. This prevents false positives on short or ambiguous text

## Step 3: Write tests

1. Unit test: LanguageDetector returns 'en' for English text
2. Unit test: LanguageDetector returns 'fr' for French text
3. Unit test: LanguageDetector returns 'en' for very short text (fallback)
4. Integration test: ingest English email → message.lang_detect = 'en'
5. Integration test: ingest French email → message.lang_detect = 'fr'

## Step 4: CS-Fixer + CI verification

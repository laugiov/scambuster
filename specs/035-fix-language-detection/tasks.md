# 035 — Tasks: Fix Language Detection

## Task 1: Fix hardcoded 'fr' in IngestHandler
- [ ] Find exact line with hardcoded `'fr'` in `IngestHandler.php`
- [ ] Inject `LanguageDetector` as optional constructor dependency (nullable)
- [ ] Call `$this->languageDetector->detect($bodyText)` on inbound message body
- [ ] If detector is null or body < 50 chars, default to `'en'`
- [ ] Store result in message entity `lang_detect` field

## Task 2: Add confidence threshold to LanguageDetector
- [ ] Read current `LanguageDetector::detect()` implementation
- [ ] If top language score is less than 20% higher than second, return 'en' (ambiguous)
- [ ] If input text is < 50 characters, return 'en' (too short for reliable detection)

## Task 3: Write unit tests for LanguageDetector
- [ ] Test: "Dear Customer, we detected suspicious activity" → 'en'
- [ ] Test: "Cher client, nous avons détecté une activité suspecte" → 'fr'
- [ ] Test: "Estimado cliente" → 'es'
- [ ] Test: "" (empty) → 'en' (default)
- [ ] Test: "Hi" (too short) → 'en' (default)
- [ ] Test: Long English phishing email → 'en'

## Task 4: Write integration tests for ingestion
- [ ] Test: ingest English RFC822 email → message.lang_detect = 'en'
- [ ] Test: ingest French RFC822 email → message.lang_detect = 'fr'
- [ ] Test: ingest email with empty body → message.lang_detect = 'en'

## Task 5: Verify reply language
- [ ] Check that ReplyHandler uses the corrected lang_detect from the message
- [ ] Verify BasePromptRules receives 'en' for English emails
- [ ] No more Portuguese/Spanish/Italian replies to English emails

## Task 6: CI verification
- [ ] `make test` passes (all 2094+ tests)
- [ ] `make stan` passes
- [ ] PHP-CS-Fixer clean
- [ ] No regressions

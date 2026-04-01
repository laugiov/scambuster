# 028 — Tasks: Persona Prompt Refactor

## Task 1: Add adaptation rules to BasePromptRules
- [ ] Edit `src/Application/LLM/Prompt/BasePromptRules.php`
- [ ] Add 3 new rules: accept attacker's name, adapt to scenario, no forced signature
- [ ] Verify rules are appended after persona prompt (recency bias)

## Task 2: Rewrite persona system prompts — Seniors (3)
- [ ] `senior_trusting`: strip Marcel Dupont/70/Lyon/"signs with full name", keep postal worker traits
- [ ] `senior_suspicious`: strip Brigitte Moreau/68/Strasbourg, keep fraud victim/skepticism/son-in-law in IT
- [ ] `senior_isolated`: strip Odette Blanchard/75/Nantes, keep widow/cat Minou/late husband Raymond/rambling

## Task 3: Rewrite persona system prompts — Business (5)
- [ ] `small_business_owner`: strip Philippe Garnier/52/Toulouse/"Boulangerie Garnier", keep bakery/4 employees/3 AM
- [ ] `entrepreneur_rushed`: strip Karim Benziane/38/Paris, keep agency CEO/typos/jargon
- [ ] `accountant_meticulous`: strip Catherine Vidal/45/Bordeaux/Mr. Lefèvre, keep 20 years invoices/VAT codes/mentions manager
- [ ] `freelance_cautious`: strip Léa Martin/34/Montpellier, keep graphic designer/home studio/scope questions
- [ ] `admin_assistant`: strip Emma Petit/29/Lille, keep 3 managers/overwhelmed/checks with manager

## Task 4: Rewrite persona system prompts — Tech (3)
- [ ] `tech_newbie`: strip Monique Faure/62/Grenoble, keep retired nurse/"internet button"/daughter set up email
- [ ] `tech_intermediate`: strip Julien Roche/40/Lyon, keep marketing manager/clears cache/curious
- [ ] `student_busy`: strip Chloé Durand/22/Rennes, keep communications student/coffee shop/"tbh"

## Task 5: Rewrite persona system prompts — Romance (3)
- [ ] `lonely_divorcee`: strip Nathalie Renard/48/Annecy, keep divorced/18 years/two teens/hiking/guarded
- [ ] `hopeless_romantic`: strip François Beaumont/55/Avignon, keep librarian/florid language/falls fast
- [ ] `widow_grieving`: strip Henri Marchand/65/Dijon/Claire, keep widowed/38 years/empty chair/melancholic

## Task 6: Rewrite persona system prompts — Banking (3)
- [ ] `bank_customer`: strip Bernard Leroy/58/Marseille, keep 30 years same account/formal/trusts official tone
- [ ] `worried_customer`: strip Sophie Dumas/42/Nantes, keep mother of three/panicking/exclamation-filled/impulsive
- [ ] `investor_greedy`: strip Thierry Roussel/50/Nice, keep COVID trading/ROI jargon/fears missing out

## Task 7: Rewrite persona system prompts — Lottery (2)
- [ ] `lottery_skeptic`: strip Damien Cartier/44/Rennes, keep systems engineer/methodical/asks proof
- [ ] `lottery_believer`: strip Gérard Fontaine/67/Biarritz, keep optimistic retiree/breathless/cruise plans/fees normal

## Task 8: Rewrite persona system prompts — Others (8)
- [ ] `lonely_person`: strip Antoine Lefèvre/35/Clermont-Ferrand, keep software tester/alone/plants/craves connection
- [ ] `confused_user`: strip Martine Bouvier/55/Limoges, keep filing/photocopies/repetitive/trusts "experts"
- [ ] `debtor_desperate`: strip Rachid Hamidi/40/Saint-Denis, keep single father/lost job/debts/desperate
- [ ] `job_seeker`: strip Thomas Girard/27/Lille, keep graduate/international business/5 months unemployed/student loans
- [ ] `buyer_eager`: strip Amélie Vasseur/33/Rouen, keep flash sales/bubbly/impulsive
- [ ] `elderly_person`: strip Sylvie Perrot/72/Aix-en-Provence/Jacqueline, keep grandmother/4 children/7 grandchildren/"screen thing"
- [ ] `generic_user`: strip Pierre Lambert/45/Tours, keep office worker/logistics/moderate/balanced
- [ ] `charity_donor`: strip Jacqueline Morel/69/Pau, keep retired pharmacist/sponsors children/food bank volunteer

## Task 9: Create Doctrine migration for production
- [ ] Generate migration with 27 UPDATE statements (one per persona_code)
- [ ] Include DOWN method with original prompts for rollback
- [ ] Test migration runs without error on dev DB

## Task 10: Verify and test
- [ ] Run `make test` — all 2074+ tests pass, 0 failures
- [ ] Grep PersonaFixtures.php for remaining proper names (should find none in system_prompt)
- [ ] Query DB: confirm no first/last names or cities in system_prompt
- [ ] Run `make quickstart` on clean env — verify fixtures load correctly
- [ ] Commit and push

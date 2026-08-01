-- ScamBuster — production reference/lookup seed (idempotent, INSERT-ONLY).
--
-- The production image is built with `composer install --no-dev`, so the Doctrine
-- fixtures bundle (and `doctrine:fixtures:load`) are absent. Database MIGRATIONS
-- seed part of the reference data (27 personas, 7 scam types) but NOT the channels,
-- directions, the full 13 scam types, the scam-type -> persona links, or an admin
-- user. This script fills those gaps.
--
-- SAFETY: every statement is guarded (WHERE NOT EXISTS / ON CONFLICT DO NOTHING).
-- It NEVER updates or deletes an existing row, so it is safe to run on every boot
-- and cannot clobber production data. New surrogate keys come from the Doctrine
-- sequences via nextval(); a setval() guard first advances each sequence past any
-- rows a migration inserted with an explicit id, so generated ids never collide.

-- ── Channels (Channel entity → lkp_channel) ─────────────────────────────────
SELECT setval('lkp_channel_channel_id_seq',
    GREATEST((SELECT COALESCE(MAX(channel_id), 0) FROM lkp_channel), 1),
    (SELECT COUNT(*) > 0 FROM lkp_channel));

INSERT INTO lkp_channel (channel_id, code, label_en, label_fr)
SELECT nextval('lkp_channel_channel_id_seq'), 'email', 'Email', 'Courriel'
WHERE NOT EXISTS (SELECT 1 FROM lkp_channel WHERE code = 'email');
INSERT INTO lkp_channel (channel_id, code, label_en, label_fr)
SELECT nextval('lkp_channel_channel_id_seq'), 'sms', 'SMS', 'SMS'
WHERE NOT EXISTS (SELECT 1 FROM lkp_channel WHERE code = 'sms');
INSERT INTO lkp_channel (channel_id, code, label_en, label_fr)
SELECT nextval('lkp_channel_channel_id_seq'), 'whatsapp', 'WhatsApp', 'WhatsApp'
WHERE NOT EXISTS (SELECT 1 FROM lkp_channel WHERE code = 'whatsapp');
INSERT INTO lkp_channel (channel_id, code, label_en, label_fr)
SELECT nextval('lkp_channel_channel_id_seq'), 'telegram', 'Telegram', 'Telegram'
WHERE NOT EXISTS (SELECT 1 FROM lkp_channel WHERE code = 'telegram');
INSERT INTO lkp_channel (channel_id, code, label_en, label_fr)
SELECT nextval('lkp_channel_channel_id_seq'), 'phone', 'Phone', 'Telephone'
WHERE NOT EXISTS (SELECT 1 FROM lkp_channel WHERE code = 'phone');

-- ── Directions (Direction entity → lkp_direction) ───────────────────────────
SELECT setval('lkp_direction_dir_id_seq',
    GREATEST((SELECT COALESCE(MAX(dir_id), 0) FROM lkp_direction), 1),
    (SELECT COUNT(*) > 0 FROM lkp_direction));

INSERT INTO lkp_direction (dir_id, code, label_en, label_fr)
SELECT nextval('lkp_direction_dir_id_seq'), 'in', 'Inbound', 'Entrant'
WHERE NOT EXISTS (SELECT 1 FROM lkp_direction WHERE code = 'in');
INSERT INTO lkp_direction (dir_id, code, label_en, label_fr)
SELECT nextval('lkp_direction_dir_id_seq'), 'out', 'Outbound', 'Sortant'
WHERE NOT EXISTS (SELECT 1 FROM lkp_direction WHERE code = 'out');

-- ── Scam types missing from the migrations (canonical set is 13; migrations
--    seed 7). The critical one is UNKNOWN: new conversations default to it, and
--    a non-null FK means ingest fatals without it. ────────────────────────────
SELECT setval('lkp_scam_type_scam_type_id_seq',
    GREATEST((SELECT COALESCE(MAX(scam_type_id), 0) FROM lkp_scam_type), 1),
    (SELECT COUNT(*) > 0 FROM lkp_scam_type));

INSERT INTO lkp_scam_type (scam_type_id, code, label, description, misp_taxonomy, attck_technique, active, created_at, updated_at)
SELECT nextval('lkp_scam_type_scam_type_id_seq'), 'UNKNOWN', 'Unclassified', 'Unidentified scam type', 'rsit:fraud="other"', NULL, true, now(), now()
WHERE NOT EXISTS (SELECT 1 FROM lkp_scam_type WHERE code = 'UNKNOWN');
INSERT INTO lkp_scam_type (scam_type_id, code, label, description, misp_taxonomy, attck_technique, active, created_at, updated_at)
SELECT nextval('lkp_scam_type_scam_type_id_seq'), 'PHISHING', 'Phishing', 'Generic phishing attempt', 'rsit:fraud="phishing"', 'T1566', true, now(), now()
WHERE NOT EXISTS (SELECT 1 FROM lkp_scam_type WHERE code = 'PHISHING');
INSERT INTO lkp_scam_type (scam_type_id, code, label, description, misp_taxonomy, attck_technique, active, created_at, updated_at)
SELECT nextval('lkp_scam_type_scam_type_id_seq'), 'PHISH_CREDENTIALS', 'Credential Phish', 'Targets login/MFA (O365, banking, webmail)', 'rsit:fraud="phishing"', 'T1566.002', true, now(), now()
WHERE NOT EXISTS (SELECT 1 FROM lkp_scam_type WHERE code = 'PHISH_CREDENTIALS');
INSERT INTO lkp_scam_type (scam_type_id, code, label, description, misp_taxonomy, attck_technique, active, created_at, updated_at)
SELECT nextval('lkp_scam_type_scam_type_id_seq'), 'INVOICE_FRAUD', 'Invoice Fraud', 'Fake invoice or bank-details change', 'rsit:fraud="fraud"', 'T1656', true, now(), now()
WHERE NOT EXISTS (SELECT 1 FROM lkp_scam_type WHERE code = 'INVOICE_FRAUD');
INSERT INTO lkp_scam_type (scam_type_id, code, label, description, misp_taxonomy, attck_technique, active, created_at, updated_at)
SELECT nextval('lkp_scam_type_scam_type_id_seq'), 'ROMANCE', 'Romance Scam', 'Builds trust, then asks for money', 'rsit:fraud="scam"', 'T1656', true, now(), now()
WHERE NOT EXISTS (SELECT 1 FROM lkp_scam_type WHERE code = 'ROMANCE');
INSERT INTO lkp_scam_type (scam_type_id, code, label, description, misp_taxonomy, attck_technique, active, created_at, updated_at)
SELECT nextval('lkp_scam_type_scam_type_id_seq'), 'TECH_SUPPORT', 'Tech Support', 'Impersonates Microsoft/Apple support', 'rsit:fraud="scam"', 'T1656', true, now(), now()
WHERE NOT EXISTS (SELECT 1 FROM lkp_scam_type WHERE code = 'TECH_SUPPORT');

-- ── Scam-type -> persona links (curated mapping mirrored from the reference
--    fixtures). Joined by natural code so no surrogate ids are hard-coded. ─────
INSERT INTO scam_type_persona (scam_type_id, persona_id)
SELECT st.scam_type_id, p.persona_id FROM lkp_scam_type st, persona p
WHERE st.code = 'PHISHING' AND p.persona_code IN ('bank_customer','worried_customer','tech_newbie','tech_intermediate','senior_trusting')
ON CONFLICT (scam_type_id, persona_id) DO NOTHING;
INSERT INTO scam_type_persona (scam_type_id, persona_id)
SELECT st.scam_type_id, p.persona_id FROM lkp_scam_type st, persona p
WHERE st.code = 'PHISH_CREDENTIALS' AND p.persona_code IN ('bank_customer','worried_customer','tech_newbie','tech_intermediate','senior_trusting')
ON CONFLICT (scam_type_id, persona_id) DO NOTHING;
INSERT INTO scam_type_persona (scam_type_id, persona_id)
SELECT st.scam_type_id, p.persona_id FROM lkp_scam_type st, persona p
WHERE st.code = 'PHISH_MALWARE' AND p.persona_code IN ('bank_customer','worried_customer','tech_newbie','tech_intermediate','senior_trusting')
ON CONFLICT (scam_type_id, persona_id) DO NOTHING;
INSERT INTO scam_type_persona (scam_type_id, persona_id)
SELECT st.scam_type_id, p.persona_id FROM lkp_scam_type st, persona p
WHERE st.code = 'INVOICE_FRAUD' AND p.persona_code IN ('small_business_owner','entrepreneur_rushed','accountant_meticulous','freelance_cautious','admin_assistant')
ON CONFLICT (scam_type_id, persona_id) DO NOTHING;
INSERT INTO scam_type_persona (scam_type_id, persona_id)
SELECT st.scam_type_id, p.persona_id FROM lkp_scam_type st, persona p
WHERE st.code = 'CEO_FRAUD' AND p.persona_code IN ('small_business_owner','entrepreneur_rushed','accountant_meticulous','freelance_cautious','admin_assistant')
ON CONFLICT (scam_type_id, persona_id) DO NOTHING;
INSERT INTO scam_type_persona (scam_type_id, persona_id)
SELECT st.scam_type_id, p.persona_id FROM lkp_scam_type st, persona p
WHERE st.code = 'ROMANCE' AND p.persona_code IN ('lonely_person','lonely_divorcee','hopeless_romantic','widow_grieving','senior_isolated')
ON CONFLICT (scam_type_id, persona_id) DO NOTHING;
INSERT INTO scam_type_persona (scam_type_id, persona_id)
SELECT st.scam_type_id, p.persona_id FROM lkp_scam_type st, persona p
WHERE st.code = 'TECH_SUPPORT' AND p.persona_code IN ('confused_user','tech_newbie','tech_intermediate','senior_trusting','senior_suspicious')
ON CONFLICT (scam_type_id, persona_id) DO NOTHING;
INSERT INTO scam_type_persona (scam_type_id, persona_id)
SELECT st.scam_type_id, p.persona_id FROM lkp_scam_type st, persona p
WHERE st.code = 'LOTTERY' AND p.persona_code IN ('lottery_skeptic','lottery_believer','elderly_person','investor_greedy','debtor_desperate')
ON CONFLICT (scam_type_id, persona_id) DO NOTHING;
INSERT INTO scam_type_persona (scam_type_id, persona_id)
SELECT st.scam_type_id, p.persona_id FROM lkp_scam_type st, persona p
WHERE st.code = 'INVESTMENT' AND p.persona_code IN ('investor_greedy','debtor_desperate','senior_trusting','lottery_believer','entrepreneur_rushed')
ON CONFLICT (scam_type_id, persona_id) DO NOTHING;
INSERT INTO scam_type_persona (scam_type_id, persona_id)
SELECT st.scam_type_id, p.persona_id FROM lkp_scam_type st, persona p
WHERE st.code = 'JOB_OFFER' AND p.persona_code IN ('student_busy','debtor_desperate','freelance_cautious','confused_user','generic_user')
ON CONFLICT (scam_type_id, persona_id) DO NOTHING;
INSERT INTO scam_type_persona (scam_type_id, persona_id)
SELECT st.scam_type_id, p.persona_id FROM lkp_scam_type st, persona p
WHERE st.code = 'CHARITY' AND p.persona_code IN ('senior_trusting','elderly_person','lonely_person','senior_isolated','hopeless_romantic')
ON CONFLICT (scam_type_id, persona_id) DO NOTHING;
INSERT INTO scam_type_persona (scam_type_id, persona_id)
SELECT st.scam_type_id, p.persona_id FROM lkp_scam_type st, persona p
WHERE st.code = 'ADVANCE_FEE_419' AND p.persona_code IN ('senior_trusting','elderly_person','debtor_desperate','lottery_believer','lonely_person')
ON CONFLICT (scam_type_id, persona_id) DO NOTHING;
INSERT INTO scam_type_persona (scam_type_id, persona_id)
SELECT st.scam_type_id, p.persona_id FROM lkp_scam_type st, persona p
WHERE st.code = 'UNKNOWN' AND p.persona_code IN ('generic_user')
ON CONFLICT (scam_type_id, persona_id) DO NOTHING;

-- ── Default admin login. PUBLIC default password — CHANGE IT after first login.
--    bcrypt(cost 13) of the documented default password. ROLE_ADMIN grants all
--    permissions implicitly, so the permissions column keeps its '[]' default. ─
INSERT INTO app_users (id, email, password_hash, roles)
SELECT gen_random_uuid(), 'user@example.com',
       '$2y$13$.ZKFmSNj6jfhxtImiOHucu45qmOodpzMT/Mq2PwWX5rkLayygMMZG',
       '["ROLE_ADMIN"]'::json
WHERE NOT EXISTS (SELECT 1 FROM app_users WHERE email = 'user@example.com');

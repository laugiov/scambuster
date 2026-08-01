-- Link multiple personas to each scam type
-- Using only the 6 baseline personas from migrations/data/insert_personas.sql
-- These correspond to the YAML files that were originally used

-- Clear existing links
DELETE FROM scam_type_persona;

-- Invoice scams - Business personas
INSERT INTO scam_type_persona (scam_type_id, persona_id)
VALUES
    (6, 7);   -- small_business_owner

-- Phishing - Banking/Tech personas
INSERT INTO scam_type_persona (scam_type_id, persona_id)
VALUES
    (2, 3);   -- bank_customer

-- Lottery - Gullible personas
INSERT INTO scam_type_persona (scam_type_id, persona_id)
VALUES
    (3, 4);   -- elderly_person

-- Romance scams - Lonely personas
INSERT INTO scam_type_persona (scam_type_id, persona_id)
VALUES
    (4, 5);   -- lonely_person

-- Tech support scams - Confused users
INSERT INTO scam_type_persona (scam_type_id, persona_id)
VALUES
    (5, 6);   -- confused_user

-- Unknown - Generic fallback persona
INSERT INTO scam_type_persona (scam_type_id, persona_id)
VALUES
    (1, 2);   -- generic_user

-- NOTE: Additional personas can be added via:
-- 1. Application interface (when implemented)
-- 2. Command: php bin/console app:persona:create
-- 3. Extending this SQL file with more INSERTs

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seed the COLD_SERVICE_SPAM scam type + its persona links in production.
 *
 * Reference/lookup rows reach the production database through migrations,
 * not fixtures (fixtures would replace real data). Unsolicited cold
 * commercial service outreach (SEO / web-dev / marketing pitches, the
 * fake-vendor / advance-fee-for-services pattern) had no bucket in the
 * taxonomy and was landing in UNKNOWN; this gives it a home.
 *
 * Idempotent: the type INSERT uses the sequence and ON CONFLICT on the
 * unique code; the persona links are inserted by a code-matched SELECT
 * and ON CONFLICT on the composite key, so re-running is safe and a
 * demo/test environment that already seeded the type via fixtures is a
 * no-op here.
 */
final class Version2026071200000000 extends AbstractMigration
{
    private const CODE = 'COLD_SERVICE_SPAM';

    /** @var list<string> */
    private const PERSONA_CODES = [
        'small_business_owner',
        'freelance_cautious',
        'accountant_meticulous',
        'entrepreneur_rushed',
    ];

    public function getDescription(): string
    {
        return 'Seed COLD_SERVICE_SPAM scam type and its persona links';
    }

    public function up(Schema $schema): void
    {
        $description = 'Unsolicited cold commercial service outreach (SEO, web/app development, marketing) '
            . 'and the fake-vendor / advance-fee-for-services pattern it often escalates into: anonymous '
            . 'senders, contact pushed to WhatsApp/Telegram, verification attachments, pressing follow-ups.';

        $this->addSql(
            "INSERT INTO lkp_scam_type (scam_type_id, code, label, description, misp_taxonomy, attck_technique, active, created_at, updated_at)
             VALUES (nextval('lkp_scam_type_scam_type_id_seq'), :code, :label, :description, :misp, NULL, true, NOW(), NOW())
             ON CONFLICT (code) DO NOTHING",
            [
                'code' => self::CODE,
                'label' => 'Cold Service Spam / Fake Vendor',
                'description' => $description,
                'misp' => 'rsit:fraud="scam"',
            ]
        );

        // Link the type to the personas a B2B service pitch plausibly
        // lands on. The SELECT resolves persona ids by code, so it
        // inserts nothing in an environment where those personas are
        // absent (no FK violation).
        $this->addSql(
            'INSERT INTO scam_type_persona (scam_type_id, persona_id)
             SELECT st.scam_type_id, p.persona_id
             FROM lkp_scam_type st
             JOIN persona p ON p.persona_code IN (:personaCodes)
             WHERE st.code = :code
             ON CONFLICT (scam_type_id, persona_id) DO NOTHING',
            [
                'code' => self::CODE,
                'personaCodes' => self::PERSONA_CODES,
            ],
            [
                'personaCodes' => \Doctrine\DBAL\ArrayParameterType::STRING,
            ]
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'DELETE FROM scam_type_persona
             WHERE scam_type_id IN (SELECT scam_type_id FROM lkp_scam_type WHERE code = :code)',
            ['code' => self::CODE]
        );
        $this->addSql('DELETE FROM lkp_scam_type WHERE code = :code', ['code' => self::CODE]);
    }
}

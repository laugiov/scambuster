<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Update all 27 persona_label to English for consistency with English system_prompt.
 */
final class Version20260327150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update persona labels from French to English';
    }

    public function up(Schema $schema): void
    {
        $labels = [
            'accountant_meticulous' => 'Meticulous and procedural accountant',
            'admin_assistant' => 'Diligent administrative assistant',
            'bank_customer' => 'Worried bank customer',
            'buyer_eager' => 'Enthusiastic and rushed buyer',
            'charity_donor' => 'Generous philanthropist retiree',
            'confused_user' => 'Confused user facing technical issues',
            'debtor_desperate' => 'Desperate debtor seeking a solution',
            'elderly_person' => 'Trusting elderly person',
            'entrepreneur_rushed' => 'Rushed and impulsive entrepreneur',
            'freelance_cautious' => 'Cautious and organized freelancer',
            'generic_user' => 'Adaptable correspondent',
            'hopeless_romantic' => 'Naive and idealistic romantic',
            'investor_greedy' => 'Greedy investor seeking quick gains',
            'job_seeker' => 'Unemployed graduate eager for work',
            'lonely_divorcee' => 'Lonely divorcee seeking a fresh start',
            'lonely_person' => 'Lonely person seeking affection',
            'lottery_believer' => 'Believer in their good fortune',
            'lottery_skeptic' => 'Cautious skeptic about winnings',
            'senior_isolated' => 'Isolated elderly person seeking contact',
            'senior_suspicious' => 'Suspicious and cautious retiree',
            'senior_trusting' => 'Retiree trusting of authorities',
            'small_business_owner' => 'Small business owner',
            'student_busy' => 'Busy and distracted student',
            'tech_intermediate' => 'Confident intermediate user',
            'tech_newbie' => 'Anxious computing beginner',
            'widow_grieving' => 'Grieving widow seeking comfort',
            'worried_customer' => 'Very worried and stressed customer',
        ];

        foreach ($labels as $code => $label) {
            $this->addSql(
                'UPDATE persona SET persona_label = :label WHERE persona_code = :code',
                ['label' => $label, 'code' => $code],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // Labels were in French before this migration
        $labels = [
            'accountant_meticulous' => 'Comptable méticuleux et procédurier',
            'admin_assistant' => 'Assistant(e) administratif(ve) appliqué(e)',
            'bank_customer' => 'Client bancaire inquiet',
            'buyer_eager' => 'Acheteur enthousiaste et pressé',
            'confused_user' => 'Utilisateur confus face à un problème technique',
            'debtor_desperate' => 'Endetté désespéré cherchant une solution',
            'elderly_person' => 'Personne âgée confiante',
            'entrepreneur_rushed' => 'Entrepreneur pressé et impulsif',
            'freelance_cautious' => 'Freelance prudent et organisé',
            'generic_user' => 'Correspondant adaptable',
            'hopeless_romantic' => 'Romantique naïf(ve) idéaliste',
            'investor_greedy' => 'Investisseur avide de gains rapides',
            'lonely_divorcee' => 'Divorcé(e) seul(e) en quête de renouveau',
            'lonely_person' => 'Personne seule en quête d\'affection',
            'lottery_believer' => 'Croyant en sa bonne fortune',
            'lottery_skeptic' => 'Sceptique prudent face aux gains',
            'senior_isolated' => 'Personne âgée isolée cherchant du contact',
            'senior_suspicious' => 'Retraité méfiant et prudent',
            'senior_trusting' => 'Retraité confiant envers les autorités',
            'small_business_owner' => 'Propriétaire de petite entreprise',
            'student_busy' => 'Étudiant pressé et distrait',
            'tech_intermediate' => 'Utilisateur intermédiaire confiant',
            'tech_newbie' => 'Débutant en informatique anxieux',
            'widow_grieving' => 'Veuf(ve) endeuillé(e) cherchant du réconfort',
            'worried_customer' => 'Client très inquiet et stressé',
        ];

        foreach ($labels as $code => $label) {
            $this->addSql(
                'UPDATE persona SET persona_label = :label WHERE persona_code = :code',
                ['label' => $label, 'code' => $code],
            );
        }
    }
}

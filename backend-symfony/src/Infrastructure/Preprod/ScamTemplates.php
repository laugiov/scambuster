<?php

declare(strict_types=1);

namespace App\Infrastructure\Preprod;

/**
 * Templates détaillés pour génération de conversations scam réalistes
 *
 * Chaque template contient :
 * - scenario: Description détaillée du contexte
 * - hook: L'hameçon psychologique
 * - progression: Étapes naturelles de l'arnaque (3-5 étapes)
 * - scammer_personality: Caractère du scammer
 * - urgency_level: Niveau de pression temporelle
 * - emotional_triggers: Leviers émotionnels utilisés
 */
class ScamTemplates
{
    /**
     * Retourne tous les templates pour un type de scam
     */
    public static function getTemplates(string $scamTypeCode): array
    {
        return match ($scamTypeCode) {
            'PHISH_CREDENTIALS' => self::getPhishingCredentialsTemplates(),
            'BEC_CEO' => self::getBecTemplates(),
            'BANK_IMPERSONATION' => self::getBankImpersonationTemplates(),
            'GOV_IMPERSONATION' => self::getGovImpersonationTemplates(),
            'TECH_SUPPORT' => self::getTechSupportTemplates(),
            'ROMANCE_SCAM' => self::getRomanceScamTemplates(),
            'ADVANCE_FEE_419' => self::getAdvanceFeeTemplates(),
            'INVESTMENT_SCAM' => self::getInvestmentScamTemplates(),
            'DELIVERY_SCAM' => self::getDeliveryScamTemplates(),
            'INVOICE_FRAUD' => self::getInvoiceFraudTemplates(),
            default => self::getGenericTemplates(),
        };
    }

    private static function getPhishingCredentialsTemplates(): array
    {
        return [
            [
                'scenario' => 'Phishing Microsoft Office 365 - Suspension imminente du compte',
                'hook' => 'Compte Microsoft sera désactivé dans 24h pour non-vérification',
                'progression' => [
                    'scammer_1' => 'Email urgent de "Microsoft Security" annonçant suspension imminente avec lien de vérification',
                    'victim_1' => 'Expression d\'inquiétude, questions sur légitimité',
                    'scammer_2' => 'Rassure avec détails techniques crédibles, insiste sur urgence avec référence ticket',
                    'victim_2' => 'Tente de vérifier, demande confirmation',
                    'scammer_3' => 'Fournit URL de vérification + numéro d\'assistance, pression temps',
                ],
                'scammer_personality' => 'Professionnel, courtois mais pressant, utilise jargon technique',
                'urgency_level' => 'high',
                'emotional_triggers' => ['peur perte accès', 'autorité technique', 'deadline strict'],
            ],
            [
                'scenario' => 'Phishing bancaire - Transaction suspecte de 1500€ détectée',
                'hook' => 'Paiement inhabituel détecté, confirmation requise pour éviter blocage carte',
                'progression' => [
                    'scammer_1' => 'SMS de la banque signalant paiement suspect avec montant précis et commerçant inconnu',
                    'victim_1' => 'Panique, affirme ne pas avoir effectué ce paiement',
                    'scammer_2' => 'Procédure de sécurité : doit confirmer identité via lien sécurisé sous 2h',
                    'victim_2' => 'Demande si peut appeler la banque directement',
                    'scammer_3' => 'Service fraude fermé, urgence absolue, fournit numéro direct + lien unique',
                ],
                'scammer_personality' => 'Agent sécurité bancaire, empathique mais strict sur procédure',
                'urgency_level' => 'critical',
                'emotional_triggers' => ['peur fraude', 'perte argent', 'autorité bancaire'],
            ],
            [
                'scenario' => 'Phishing LinkedIn - Offre emploi attractive nécessitant création compte',
                'hook' => 'Recruteur GAFAM propose entretien, demande profil complet sur portail RH',
                'progression' => [
                    'scammer_1' => 'Message LinkedIn de faux recruteur avec offre senior bien payée, lien formulaire',
                    'victim_1' => 'Intérêt fort, questions sur poste et entreprise',
                    'scammer_2' => 'Détails poste alléchants, deadline candidature courte, portail RH "confidentiel"',
                    'victim_2' => 'Demande pourquoi pas via LinkedIn directement',
                    'scammer_3' => 'Explique process interne sécurisé, autres candidats en attente, opportunity window',
                ],
                'scammer_personality' => 'Recruteur senior corporate, professionnel, laisse entendre compétition',
                'urgency_level' => 'medium',
                'emotional_triggers' => ['opportunité carrière', 'compétition', 'exclusivité'],
            ],
        ];
    }

    private static function getBecTemplates(): array
    {
        return [
            [
                'scenario' => 'CEO demande virement urgent pour acquisition confidentielle',
                'hook' => 'PDG en déplacement à l\'étranger demande virement discret et rapide',
                'progression' => [
                    'scammer_1' => 'Email du "CEO" depuis adresse proche officielle, ton autoritaire mais courtois, acquisition confidentielle',
                    'victim_1' => 'Confirmation volonté d\'aider, demande détails procédure habituelle',
                    'scammer_2' => 'Insiste confidentialité absolue, avocat gérera paperasse après, urgence fermeture deal',
                    'victim_2' => 'Hésite, mentionne protocole validation CFO',
                    'scammer_3' => 'Irritation contrôlée, rappelle confiance, CFO informé, menace perte opportunité business',
                ],
                'scammer_personality' => 'CEO autoritaire mais cordial, stressé par deal, ton corporate',
                'urgency_level' => 'high',
                'emotional_triggers' => ['autorité hiérarchique', 'confidentialité', 'peur décevoir boss'],
            ],
            [
                'scenario' => 'Fausse facture fournisseur avec changement de RIB',
                'hook' => 'Email de fournisseur habituel annonçant changement coordonnées bancaires',
                'progression' => [
                    'scammer_1' => 'Email du fournisseur connu avec facture habituelle mais nouveau RIB (fusion bancaire)',
                    'victim_1' => 'Accuse réception, demande confirmation écrite changement',
                    'scammer_2' => 'Pièce jointe "certificat banque", autres clients déjà notifiés, paiement attendu',
                    'victim_2' => 'Veut appeler contact habituel pour vérifier',
                    'scammer_3' => 'Contact en congés, joignable que par email, retard paiement = pénalités contrat',
                ],
                'scammer_personality' => 'Comptable fournisseur, procédurier, légèrement impatient',
                'urgency_level' => 'medium',
                'emotional_triggers' => ['relation commerciale', 'pénalités', 'routine perturbée'],
            ],
        ];
    }

    private static function getBankImpersonationTemplates(): array
    {
        return [
            [
                'scenario' => 'Appel frauduleux service anti-fraude bancaire',
                'hook' => 'Conseiller sécurité détecte tentative piratage en cours sur compte',
                'progression' => [
                    'scammer_1' => 'Appel urgent du service fraude, tentatives connexion Roumanie, doit sécuriser compte immédiatement',
                    'victim_1' => 'Panique, demande ce qu\'il doit faire',
                    'scammer_2' => 'Procédure sécurisation : vérification identité puis nouveau code sécurité par SMS',
                    'victim_2' => 'Fournit infos demandées, attend SMS',
                    'scammer_3' => 'Demande code reçu pour finaliser sécurisation (en réalité = validation transaction frauduleuse)',
                ],
                'scammer_personality' => 'Agent sécurité banque, ton grave et protecteur, utilise numéro ID agent',
                'urgency_level' => 'critical',
                'emotional_triggers' => ['peur piratage', 'protection argent', 'confiance autorité'],
            ],
        ];
    }

    private static function getGovImpersonationTemplates(): array
    {
        return [
            [
                'scenario' => 'Faux remboursement impôts avec formulaire en ligne',
                'hook' => 'Notification officielle : remboursement trop-perçu à réclamer sous 72h',
                'progression' => [
                    'scammer_1' => 'Email "impots.gouv" style officiel, remboursement 327€ calculé, lien formulaire sécurisé',
                    'victim_1' => 'Surpris positivement, demande si normal de passer par email',
                    'scammer_2' => 'Explique digitalisation services publics, formulaire sur espace sécurisé impots.gouv',
                    'victim_2' => 'Veut vérifier sur son espace personnel habituel',
                    'scammer_3' => 'Remboursement pas encore affiché (délai technique), formulaire accélère traitement',
                ],
                'scammer_personality' => 'Agent administration fiscale, ton neutre administratif, références légales',
                'urgency_level' => 'medium',
                'emotional_triggers' => ['argent gratuit', 'autorité État', 'deadline bureaucratique'],
            ],
        ];
    }

    private static function getTechSupportTemplates(): array
    {
        return [
            [
                'scenario' => 'Pop-up alerte virus + faux support Microsoft',
                'hook' => 'Écran bloqué avec alerte "Trojan détecté", numéro support à appeler d\'urgence',
                'progression' => [
                    'scammer_1' => 'Technicien "Microsoft" répond, confirme infection grave, propose prise main à distance',
                    'victim_1' => 'Paniqué par écran bloqué, demande combien ça coûte',
                    'scammer_2' => 'Diagnostic gratuit, installation AnyDesk pour analyse, trouve "127 menaces"',
                    'victim_2' => 'Inquiet, demande solution',
                    'scammer_3' => 'Licence antivirus pro 5 ans = 299€ OU cartes iTunes (paiement "sécurisé")',
                ],
                'scammer_personality' => 'Technicien indien accent fort, patient mais insistant, jargon technique',
                'urgency_level' => 'critical',
                'emotional_triggers' => ['peur perte données', 'ordinateur inutilisable', 'autorité technique'],
            ],
        ];
    }

    private static function getRomanceScamTemplates(): array
    {
        return [
            [
                'scenario' => 'Arnaque sentimentale - ingénieur pétrolier bloqué à l\'étranger',
                'hook' => 'Relation en ligne développée, soudain urgence médicale/financière à l\'étranger',
                'progression' => [
                    'scammer_1' => 'Après semaines échanges romantiques, annonce urgence : accident chantier, hôpital Ghana, besoin caution',
                    'victim_1' => 'Choqué et inquiet, propose aide, demande détails',
                    'scammer_2' => 'Récit détaillé dramatique, montant précis (3500€), remboursera dès sortie hôpital',
                    'victim_2' => 'Hésite sur montant, propose moins ou prêt familial',
                    'scammer_3' => 'Émotion, déception, rappelle sentiment partagé, promesse future rencontre, Western Union seul moyen',
                ],
                'scammer_personality' => 'Romantique, vulnérable, excellent communicant, patient sur long terme',
                'urgency_level' => 'medium',
                'emotional_triggers' => ['amour/attachement', 'culpabilité', 'promesse future', 'détresse personne aimée'],
            ],
        ];
    }

    private static function getAdvanceFeeTemplates(): array
    {
        return [
            [
                'scenario' => 'Héritage inattendu d\'un parent éloigné africain',
                'hook' => 'Avocat annonce héritage millions USD, frais déblocage à avancer',
                'progression' => [
                    'scammer_1' => 'Email avocat Afrique du Sud, parent décédé, héritage 8.5M USD, recherche héritier même nom',
                    'victim_1' => 'Scepticisme initial, demande preuves',
                    'scammer_2' => 'Documents légaux scannés impressionnants, arbre généalogique, urgence succession',
                    'victim_2' => 'Intéressé, demande procédure',
                    'scammer_3' => 'Frais notaire/banque 4500€ à avancer, remboursés sur héritage, chance unique',
                ],
                'scammer_personality' => 'Avocat international distingué, formel, documentation abondante',
                'urgency_level' => 'low',
                'emotional_triggers' => ['argent facile', 'opportunité unique', 'légitimité apparente'],
            ],
        ];
    }

    private static function getInvestmentScamTemplates(): array
    {
        return [
            [
                'scenario' => 'Plateforme trading crypto avec bonus inscription',
                'hook' => 'Opportunité investissement crypto, gains garantis 15%/mois, bonus 200€',
                'progression' => [
                    'scammer_1' => 'Pub ciblée puis conseiller "expert", démo plateforme, témoignages gains, bonus inscription limité',
                    'victim_1' => 'Intrigué, demande garanties et comment ça marche',
                    'scammer_2' => 'Explications algorithme IA, licence régulée (fausse), premiers petits gains visibles compte démo',
                    'victim_2' => 'Veut commencer petit (500€)',
                    'scammer_3' => 'Accepte, gains affichés rapidement, propose augmenter pour maximiser rendement',
                ],
                'scammer_personality' => 'Conseiller financier pro, enthousiaste mais pas insistant, chiffres précis',
                'urgency_level' => 'low',
                'emotional_triggers' => ['cupidité', 'FOMO', 'confiance chiffres', 'preuve sociale'],
            ],
        ];
    }

    private static function getDeliveryScamTemplates(): array
    {
        return [
            [
                'scenario' => 'SMS faux Chronopost - colis bloqué en douane',
                'hook' => 'Colis en attente, frais douane 2.99€ à payer en ligne sous 48h',
                'progression' => [
                    'scammer_1' => 'SMS "Chronopost" : colis international bloqué, frais douane minimes, lien paiement',
                    'victim_1' => 'N\'attend pas colis, mais doute, clique lien par curiosité',
                    'scammer_2' => 'Site clone crédible, tracking number, formulaire CB pour 2.99€',
                    'victim_2' => 'Hésite à donner CB pour si petit montant',
                    'scammer_3' => 'Pas d\'autre moyen, sinon retour expéditeur, petite somme, process standard',
                ],
                'scammer_personality' => 'Automatisé puis service client neutre, procédurier',
                'urgency_level' => 'medium',
                'emotional_triggers' => ['curiosité colis', 'petit montant', 'urgence délai'],
            ],
        ];
    }

    private static function getInvoiceFraudTemplates(): array
    {
        return [
            [
                'scenario' => 'Email intercepté et modifié - vraie facture, faux RIB',
                'hook' => 'Facture fournisseur légitime mais RIB modifié par MITM attack',
                'progression' => [
                    'scammer_1' => 'Email thread existant, facture PDF authentique mais RIB changé en pièce jointe',
                    'victim_1' => 'Traite normalement, prépare virement',
                    'scammer_2' => 'Si question, "relance" automatique avec même faux RIB',
                    'victim_2' => 'Effectue paiement sans suspicion',
                    'scammer_3' => 'Vrai fournisseur relance impayé quelques jours plus tard = découverte fraude',
                ],
                'scammer_personality' => 'Invisible (hack email), ou comptable si contact',
                'urgency_level' => 'low',
                'emotional_triggers' => ['routine process', 'confiance relation établie'],
            ],
        ];
    }

    private static function getGenericTemplates(): array
    {
        return [
            [
                'scenario' => 'Tentative arnaque générique par email',
                'hook' => 'Offre suspecte ou demande inhabituelle',
                'progression' => [
                    'scammer_1' => 'Email initial avec proposition ou alerte',
                    'victim_1' => 'Réponse prudente, questions',
                    'scammer_2' => 'Tentative convaincre avec détails',
                ],
                'scammer_personality' => 'Variable selon contexte',
                'urgency_level' => 'medium',
                'emotional_triggers' => ['curiosité', 'opportunité'],
            ],
        ];
    }
}

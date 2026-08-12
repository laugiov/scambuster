<?php

declare(strict_types=1);

namespace App\Domain\Communication;

/**
 * The canonical ScamBuster TTP taxonomy: the closed vocabulary of scammer-side
 * behaviours observable in an inbound message, across the six-phase scam kill chain.
 *
 * This constant is the single source of truth production code reads from. It is
 * never hand-edited downstream: the taxonomy artifact (docs/standards/taxonomy-v1.0.json),
 * the MISP machine tag file and the STIX attack-pattern catalogue are all generated
 * from here.
 *
 * Two other copies of these rows exist by design, and a consistency test locks all
 * three against each other so they can never drift:
 *
 * - the lkp_ttp migration seeds, because reference rows reach a production database
 *   through migrations and a migration must stay self-contained (it cannot depend on
 *   application code that may change under it);
 * - {@see \App\DataFixtures\Communication\TtpFixtures}, which aliases this constant
 *   for test databases.
 *
 * Changing these rows is a taxonomy change: it needs a {@see Ttp::TAXONOMY_VERSION}
 * bump and a changelog entry, and CI fails without them. Codes are deprecated
 * (active = false), never deleted — see docs/standards/taxonomy-versioning.md.
 */
final class TtpTaxonomySeed
{
    /**
     * @var list<array{code: string, label: string, definition: string, phase: string, examples: list<string>, stimulus_affinity: list<string>, external_refs: list<array{source_name: string, external_id: string}>}>
     */
    public const ENTRIES = [
        [
            'code' => 'SB-T001',
            'label' => 'Unsolicited opportunity lure',
            'definition' => 'Presents an unsolicited windfall or business opportunity (inheritance, lottery, contract, compensation) as the reason for contact.',
            'phase' => 'hook',
            'examples' => [
                'You have been selected as the beneficiary of $10.5M',
                'Your email address won our international lottery',
                'We seek a reliable partner for an oil contract',
            ],
            'stimulus_affinity' => ['PASSIVE'],
            'external_refs' => [
                ['source_name' => 'mitre-attack', 'external_id' => 'T1566'],
            ],
        ],
        [
            'code' => 'SB-T002',
            'label' => 'Institutional authority impersonation',
            'definition' => 'Claims to represent a government body, bank, court, police or international organization to command compliance.',
            'phase' => 'hook',
            'examples' => [
                'I am the director of the UN compensation commission',
                'This is the FBI fraud division',
                'I write on behalf of the Central Bank',
            ],
            'stimulus_affinity' => ['PASSIVE', 'UNKNOWN'],
            'external_refs' => [
                ['source_name' => 'mitre-attack', 'external_id' => 'T1656'],
            ],
        ],
        [
            'code' => 'SB-T003',
            'label' => 'Commercial brand impersonation',
            'definition' => 'Poses as a known company or consumer service (courier, marketplace, vendor) to exploit its established trust.',
            'phase' => 'hook',
            'examples' => [
                'DHL: your package is on hold pending fees',
                'Your account requires immediate validation',
                'Invoice attached from our sales department',
            ],
            'stimulus_affinity' => ['PASSIVE'],
            'external_refs' => [
                ['source_name' => 'mitre-attack', 'external_id' => 'T1656'],
            ],
        ],
        [
            'code' => 'SB-T004',
            'label' => 'Malicious resource delivery',
            'definition' => 'Sends a link or attachment the target must open to proceed (portal, form, document, tracking page).',
            'phase' => 'hook',
            'examples' => [
                'Click here to verify your identity',
                'Open the attached invoice',
                'Track your package at this link',
            ],
            'stimulus_affinity' => ['PASSIVE', 'DIRECT_REQUEST'],
            'external_refs' => [
                ['source_name' => 'mitre-attack', 'external_id' => 'T1566.001'],
                ['source_name' => 'mitre-attack', 'external_id' => 'T1566.002'],
            ],
        ],
        [
            'code' => 'SB-T005',
            'label' => 'Legitimacy document display',
            'definition' => 'Volunteers official-looking documents (certificates, IDs, receipts, contracts) to prove authenticity.',
            'phase' => 'trust-building',
            'examples' => [
                'Please find attached the certificate of deposit',
                'Here is a copy of my international passport',
                'See the court approval document',
            ],
            'stimulus_affinity' => ['DOCUMENT_REQUEST'],
            'external_refs' => [],
        ],
        [
            'code' => 'SB-T006',
            'label' => 'Rapport personalization',
            'definition' => 'Builds an emotional or personal bond (affection, flattery, shared life details) unrelated to the transaction itself.',
            'phase' => 'trust-building',
            'examples' => [
                'My dear, I feel I can trust you',
                'You remind me of my late wife',
                'How is your family doing?',
            ],
            'stimulus_affinity' => ['TRUST_BUILDING'],
            'external_refs' => [],
        ],
        [
            'code' => 'SB-T007',
            'label' => 'Religious or moral appeal',
            'definition' => 'Invokes God, faith or moral duty to frame the exchange as righteous and the target as chosen.',
            'phase' => 'trust-building',
            'examples' => [
                'God has directed me to you',
                'As a God-fearing man, I know you will not betray this trust',
            ],
            'stimulus_affinity' => ['TRUST_BUILDING', 'PASSIVE'],
            'external_refs' => [],
        ],
        [
            'code' => 'SB-T008',
            'label' => 'Fabricated social proof',
            'definition' => 'Cites invented third parties who already benefited from or vouched for the scheme.',
            'phase' => 'trust-building',
            'examples' => [
                'Mr John from Canada received his payment last week',
                'Many beneficiaries have already been paid this month',
            ],
            'stimulus_affinity' => ['TRUST_BUILDING'],
            'external_refs' => [],
        ],
        [
            'code' => 'SB-T009',
            'label' => 'Secrecy demand',
            'definition' => 'Instructs the target to keep the transaction confidential from family, banks or authorities.',
            'phase' => 'trust-building',
            'examples' => [
                'Tell no one about this transaction for security reasons',
                'This must remain strictly between us',
            ],
            'stimulus_affinity' => ['TRUST_BUILDING'],
            'external_refs' => [],
        ],
        [
            'code' => 'SB-T010',
            'label' => 'Intermediary introduction',
            'definition' => 'Introduces an additional persona (lawyer, banker, diplomat, agent) who must now be dealt with.',
            'phase' => 'trust-building',
            'examples' => [
                'Contact my barrister at the address below',
                'The bank director will write to you directly',
            ],
            'stimulus_affinity' => ['DIRECT_REQUEST', 'DOCUMENT_REQUEST'],
            'external_refs' => [],
        ],
        [
            'code' => 'SB-T011',
            'label' => 'Plausibility repair',
            'definition' => 'Explains away inconsistencies, delays or contradictions the target has noticed.',
            'phase' => 'trust-building',
            'examples' => [
                'The delay is due to the Easter holidays',
                'The fee changed because of new government regulations',
                'My other email was hacked, hence this address',
            ],
            'stimulus_affinity' => ['DOCUMENT_REQUEST', 'DIRECT_REQUEST'],
            'external_refs' => [],
        ],
        [
            'code' => 'SB-T012',
            'label' => 'Advance fee demand',
            'definition' => 'Requires an upfront payment to unlock a larger promised value (transfer charge, tax, insurance).',
            'phase' => 'payment-request',
            'examples' => [
                'A transfer charge of $850 is required before release',
                'Pay the 2% insurance fee to activate the account',
            ],
            'stimulus_affinity' => ['PAYMENT_INITIATION', 'DIRECT_REQUEST'],
            'external_refs' => [],
        ],
        [
            'code' => 'SB-T013',
            'label' => 'Payment instrument designation',
            'definition' => 'Provides concrete payment coordinates the target must pay to (bank account, crypto wallet, money-transfer recipient).',
            'phase' => 'payment-request',
            'examples' => [
                'Wire the amount to IBAN DE89...',
                'Send USDT to this wallet',
                'Western Union to the following receiver name',
            ],
            'stimulus_affinity' => ['PAYMENT_INITIATION', 'DIRECT_REQUEST'],
            'external_refs' => [],
        ],
        [
            'code' => 'SB-T014',
            'label' => 'Victim data harvesting',
            'definition' => 'Requests the target\'s personal, financial or authentication data (identity documents, bank details, credentials, verification codes, address, phone).',
            'phase' => 'payment-request',
            'examples' => [
                'Send a copy of your ID and your bank details',
                'Fill the beneficiary form with your full information',
                'Confirm your login and the code you received by SMS',
            ],
            'stimulus_affinity' => ['DIRECT_REQUEST'],
            'external_refs' => [
                ['source_name' => 'mitre-attack', 'external_id' => 'T1598'],
            ],
        ],
        [
            'code' => 'SB-T015',
            'label' => 'Overpayment refund scheme',
            'definition' => 'Claims an overpayment or presents fake payment proof, then asks part of the money back.',
            'phase' => 'payment-request',
            'examples' => [
                'We transferred $2,000 by mistake, kindly refund $500',
                'See attached proof of our payment; return the excess via gift cards',
            ],
            'stimulus_affinity' => ['PAYMENT_INITIATION'],
            'external_refs' => [],
        ],
        [
            'code' => 'SB-T016',
            'label' => 'Payment method shift',
            'definition' => 'Changes the demanded payment rail mid-conversation (bank → crypto → gift cards → cash agent).',
            'phase' => 'payment-request',
            'examples' => [
                'The bank route failed, buy Apple gift cards instead',
                'Western Union is blocked here, use Bitcoin',
            ],
            'stimulus_affinity' => ['PAYMENT_INITIATION'],
            'external_refs' => [],
        ],
        [
            'code' => 'SB-T017',
            'label' => 'Urgency deadline pressure',
            'definition' => 'Imposes a hard deadline or countdown to force immediate action.',
            'phase' => 'escalation',
            'examples' => [
                'You have 24 hours or the file will be closed',
                'The offer expires tonight',
            ],
            'stimulus_affinity' => ['PASSIVE'],
            'external_refs' => [],
        ],
        [
            'code' => 'SB-T018',
            'label' => 'Fee laddering',
            'definition' => 'Adds new, previously unmentioned fees after an earlier payment or agreement.',
            'phase' => 'escalation',
            'examples' => [
                'Customs now requires an anti-terrorism clearance fee',
                'One last charge of $320 and the funds are released',
            ],
            'stimulus_affinity' => ['PAYMENT_INITIATION'],
            'external_refs' => [],
        ],
        [
            'code' => 'SB-T019',
            'label' => 'Bureaucratic obstacle fabrication',
            'definition' => 'Invents official procedures, codes or certificates that block progress until resolved (the narrative around the fee).',
            'phase' => 'escalation',
            'examples' => [
                'The Certificate of Ownership Transfer (COT) must be obtained first',
                'An IMF clearance code is mandatory for international transfers',
            ],
            'stimulus_affinity' => ['DOCUMENT_REQUEST', 'PAYMENT_INITIATION'],
            'external_refs' => [],
        ],
        [
            'code' => 'SB-T020',
            'label' => 'Threat of loss or legal action',
            'definition' => 'Threatens forfeiture, arrest, account closure or legal consequences for non-compliance.',
            'phase' => 'escalation',
            'examples' => [
                'Your funds will be confiscated by the government',
                'A warrant will be issued against you',
                'Your account will be permanently blocked',
            ],
            'stimulus_affinity' => ['URGENCY_PRESSURE', 'DIRECT_REQUEST'],
            'external_refs' => [],
        ],
        [
            'code' => 'SB-T021',
            'label' => 'Emotional pressure appeal',
            'definition' => 'Leverages pity, guilt or personal hardship to compel action.',
            'phase' => 'escalation',
            'examples' => [
                'My daughter is in the hospital, please hurry',
                'I have spent my last money on these charges, do not abandon me',
            ],
            'stimulus_affinity' => ['TRUST_BUILDING', 'URGENCY_PRESSURE'],
            'external_refs' => [],
        ],
        [
            'code' => 'SB-T022',
            'label' => 'Verification deflection',
            'definition' => 'Refuses, evades or disqualifies the target\'s verification requests (contract, company registry, statement of work).',
            'phase' => 'escalation',
            'examples' => [
                'There is no time for contracts, trust me',
                'Our company registry is confidential',
                'Lawyers will only delay your payment',
            ],
            'stimulus_affinity' => ['DOCUMENT_REQUEST'],
            'external_refs' => [],
        ],
        [
            'code' => 'SB-T023',
            'label' => 'Off-channel solicitation',
            'definition' => 'Asks to continue the exchange on another channel (WhatsApp, Telegram, phone, personal email).',
            'phase' => 'channel-switch',
            'examples' => [
                'WhatsApp me at +234...',
                'Add me on Telegram for faster communication',
                'Reply to my private email',
            ],
            'stimulus_affinity' => ['DIRECT_REQUEST', 'TRUST_BUILDING'],
            'external_refs' => [],
        ],
        [
            'code' => 'SB-T024',
            'label' => 'Contact exclusivity demand',
            'definition' => 'Restricts communication to a single designated contact, address or channel and forbids others.',
            'phase' => 'channel-switch',
            'examples' => [
                'Reply only to this email address',
                'Deal exclusively with our agent; do not contact the bank directly',
            ],
            'stimulus_affinity' => ['DIRECT_REQUEST'],
            'external_refs' => [],
        ],
        [
            'code' => 'SB-T025',
            'label' => 'Final ultimatum',
            'definition' => 'Issues a last demand with the threatened termination of the deal or relationship.',
            'phase' => 'exit',
            'examples' => [
                'This is my final message: pay today or lose everything',
                'I will hand your file to another beneficiary tomorrow',
            ],
            'stimulus_affinity' => ['URGENCY_PRESSURE'],
            'external_refs' => [],
        ],
        [
            'code' => 'SB-T026',
            'label' => 'Re-engagement attempt',
            'definition' => 'Returns after silence, refusal or a stalled exchange with a new angle, discount or persona.',
            'phase' => 'exit',
            'examples' => [
                'Since you did not respond, the fee is now reduced to $300',
                'I am contacting you again with a better arrangement',
                'My colleague has taken over your file',
            ],
            'stimulus_affinity' => ['PASSIVE'],
            'external_refs' => [],
        ],
        [
            'code' => 'SB-T027',
            'label' => 'Fabricated gains display',
            'definition' => 'Shows fabricated profits, balances or returns as proof the scheme works, to induce further payment.',
            'phase' => 'trust-building',
            'examples' => [
                'Your account balance is now $12,400, up 34% this week',
                'Your first deposit earned $780, imagine what $2,000 would make',
                'See the attached dashboard screenshot with your profit',
            ],
            'stimulus_affinity' => ['PAYMENT_INITIATION', 'TRUST_BUILDING'],
            'external_refs' => [],
        ],
    ];

    /**
     * The taxonomy rows, with their shape stated in a form static analysis
     * honours.
     *
     * PHPStan infers a class constant's type from its literal value, and for a
     * 27-row nested array that collapses to a union wide enough to make every
     * `$entry['code']` a possible-invalid-array-key. Reading the rows through a
     * typed accessor gives callers the real shape without either side asserting
     * anything the other has to trust.
     *
     * @return list<array{code: string, label: string, definition: string, phase: string, examples: list<string>, stimulus_affinity: list<string>, external_refs: list<array{source_name: string, external_id: string}>}>
     */
    public static function entries(): array
    {
        return self::ENTRIES;
    }

    /**
     * Every taxonomy code, in canonical order.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_column(self::ENTRIES, 'code');
    }
}

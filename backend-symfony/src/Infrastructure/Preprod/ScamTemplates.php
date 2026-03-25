<?php

declare(strict_types=1);

namespace App\Infrastructure\Preprod;

/**
 * Detailed templates for realistic scam conversation generation (ENGLISH)
 *
 * Each template contains:
 * - scenario: Detailed context description
 * - hook: The psychological bait
 * - progression: Natural steps of the scam (3-5 steps)
 * - scammer_personality: Scammer's character traits
 * - urgency_level: Time pressure level (low/medium/high/critical)
 * - emotional_triggers: Emotional levers used
 *
 * 3 variants per scam type to maximize diversity in generated conversations.
 */
class ScamTemplates
{
    /**
     * Returns all templates for a given scam type
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getTemplates(string $scamTypeCode): array
    {
        return match ($scamTypeCode) {
            'PHISH_CREDENTIALS' => self::getPhishingCredentialsTemplates(),
            'BEC_CEO' => self::getBecTemplates(),
            'BANK_IMPERSONATION' => self::getBankImpersonationTemplates(),
            'GOV_IMPERSONATION' => self::getGovImpersonationTemplates(),
            'TECH_SUPPORT' => self::getTechSupportTemplates(),
            'ROMANCE' => self::getRomanceScamTemplates(),
            'ADVANCE_FEE_419' => self::getAdvanceFeeTemplates(),
            'INVESTMENT_SCAM' => self::getInvestmentScamTemplates(),
            'DELIVERY_SCAM' => self::getDeliveryScamTemplates(),
            'INVOICE_FRAUD' => self::getInvoiceFraudTemplates(),
            default => self::getGenericTemplates(),
        };
    }

    /** @return array<int, array<string, mixed>> */
    private static function getPhishingCredentialsTemplates(): array
    {
        return [
            [
                'scenario' => 'Microsoft Office 365 phishing - Imminent account suspension',
                'hook' => 'Your Microsoft account will be deactivated within 24 hours due to failed verification',
                'progression' => [
                    'scammer_1' => 'Urgent email from "Microsoft Security Team" warning of imminent suspension with verification link and case number',
                    'victim_1' => 'Expresses concern about losing access, asks if the email is legitimate',
                    'scammer_2' => 'Reassures with technical details about compliance audit, references ticket ID and deadline',
                    'victim_2' => 'Tries to verify independently, asks for confirmation steps',
                    'scammer_3' => 'Provides "secure" verification URL and a support hotline number, increases urgency',
                ],
                'scammer_personality' => 'Professional, courteous but pressing, uses technical jargon and Microsoft branding',
                'urgency_level' => 'high',
                'emotional_triggers' => ['fear of losing access', 'authority of tech giant', 'strict deadline'],
            ],
            [
                'scenario' => 'Banking phishing - Suspicious $1,500 transaction detected on your account',
                'hook' => 'Unusual payment flagged - confirm identity to prevent card freeze',
                'progression' => [
                    'scammer_1' => 'SMS from "your bank" reporting a suspicious $1,489.99 charge at an electronics store in another state',
                    'victim_1' => 'Panics, says they did not make that purchase, asks what to do',
                    'scammer_2' => 'Initiates "fraud prevention protocol" - must verify identity through secure link within 2 hours',
                    'victim_2' => 'Asks if they can call the bank directly instead',
                    'scammer_3' => 'Claims fraud department is overwhelmed, provides "direct agent line" and a unique secure link',
                ],
                'scammer_personality' => 'Bank security agent, empathetic but strict about procedures, uses badge number',
                'urgency_level' => 'critical',
                'emotional_triggers' => ['fear of financial loss', 'bank authority', 'time pressure'],
            ],
            [
                'scenario' => 'LinkedIn phishing - Attractive job offer requiring portal registration',
                'hook' => 'Recruiter from a Fortune 500 company offers a senior role, requests profile on "internal HR portal"',
                'progression' => [
                    'scammer_1' => 'LinkedIn message from fake recruiter: senior position, $180K salary, link to "confidential application form"',
                    'victim_1' => 'Very interested, asks about the role details and team',
                    'scammer_2' => 'Shares enticing job details, short application window, "secure HR portal" for background check',
                    'victim_2' => 'Asks why not apply through LinkedIn directly',
                    'scammer_3' => 'Explains internal security policy, other candidates already in pipeline, window closing soon',
                ],
                'scammer_personality' => 'Senior corporate recruiter, polished, implies competition among candidates',
                'urgency_level' => 'medium',
                'emotional_triggers' => ['career opportunity', 'competition with other candidates', 'exclusivity'],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function getBecTemplates(): array
    {
        return [
            [
                'scenario' => 'CEO requests urgent wire transfer for confidential acquisition',
                'hook' => 'CEO traveling abroad needs discreet and immediate wire transfer for a deal',
                'progression' => [
                    'scammer_1' => 'Email from "CEO" using a look-alike domain, authoritative but cordial tone, references a confidential M&A deal',
                    'victim_1' => 'Willing to help, asks about standard approval procedure',
                    'scammer_2' => 'Insists on absolute secrecy, says lawyer will handle paperwork, deal must close today',
                    'victim_2' => 'Hesitates, mentions CFO validation protocol',
                    'scammer_3' => 'Shows controlled irritation, reminds of trust, claims CFO is already informed, threatens loss of business opportunity',
                ],
                'scammer_personality' => 'Authoritative CEO, stressed by deal timeline, corporate tone, name-drops board members',
                'urgency_level' => 'high',
                'emotional_triggers' => ['hierarchical authority', 'confidentiality', 'fear of disappointing boss'],
            ],
            [
                'scenario' => 'Vendor invoice with changed bank details (man-in-the-middle)',
                'hook' => 'Email from known vendor announcing new banking coordinates due to "bank merger"',
                'progression' => [
                    'scammer_1' => 'Email from spoofed vendor address with regular invoice but modified bank account (claims bank merger)',
                    'victim_1' => 'Acknowledges receipt, requests written confirmation of bank change',
                    'scammer_2' => 'Attaches fake "bank certificate", says other clients already notified, payment expected on schedule',
                    'victim_2' => 'Wants to call usual vendor contact to verify',
                    'scammer_3' => 'Claims contact is on leave, only reachable by email, warns of late payment penalties per contract',
                ],
                'scammer_personality' => 'Vendor accountant, procedural, slightly impatient about payment delays',
                'urgency_level' => 'medium',
                'emotional_triggers' => ['business relationship', 'contractual penalties', 'routine disrupted'],
            ],
            [
                'scenario' => 'CFO impersonation requesting payroll redirect for new employees',
                'hook' => 'Fake CFO email asking HR to set up direct deposit for batch of new hires to a controlled account',
                'progression' => [
                    'scammer_1' => 'Email from "CFO" to HR manager: urgent payroll setup for 5 new hires starting Monday, spreadsheet attached with bank details',
                    'victim_1' => 'Confirms receipt, asks if onboarding paperwork is complete',
                    'scammer_2' => 'Says paperwork is being finalized, payroll setup cannot wait, references new department head by name',
                    'victim_2' => 'Asks to verify with department head directly',
                    'scammer_3' => 'Department head "in orientation sessions all week", escalates urgency, payroll deadline is tomorrow',
                ],
                'scammer_personality' => 'Busy CFO, short sentences, cc\'s fake assistant, uses corporate acronyms',
                'urgency_level' => 'high',
                'emotional_triggers' => ['authority', 'fear of delaying new hires', 'corporate pressure'],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function getBankImpersonationTemplates(): array
    {
        return [
            [
                'scenario' => 'Fraudulent call from bank anti-fraud department',
                'hook' => 'Security agent detects ongoing hacking attempt on your account',
                'progression' => [
                    'scammer_1' => 'Urgent call from "fraud department": login attempts from Eastern Europe detected, must secure account immediately',
                    'victim_1' => 'Panics, asks what they should do',
                    'scammer_2' => 'Security procedure: identity verification followed by new security code via SMS',
                    'victim_2' => 'Provides requested information, waits for SMS',
                    'scammer_3' => 'Asks for received SMS code to "finalize security update" (actually validates a fraudulent transaction)',
                ],
                'scammer_personality' => 'Bank security officer, grave and protective tone, uses agent ID number and case reference',
                'urgency_level' => 'critical',
                'emotional_triggers' => ['fear of being hacked', 'protecting savings', 'trusting authority'],
            ],
            [
                'scenario' => 'Fake bank branch manager calling about suspicious ATM withdrawals',
                'hook' => 'Multiple ATM withdrawals in different cities detected on your debit card',
                'progression' => [
                    'scammer_1' => 'Call from "branch manager": 3 ATM withdrawals totaling $2,400 in Miami and Chicago in the last hour, is this you?',
                    'victim_1' => 'Shocked, confirms they are not in those cities, demands card be blocked',
                    'scammer_2' => 'Says card will be blocked but needs to verify identity first to prevent further damage, asks for card number and CVV',
                    'victim_2' => 'Hesitates about giving card details over phone',
                    'scammer_3' => 'Reassures this is standard protocol, provides fake "verification ID", warns that every minute more money could be withdrawn',
                ],
                'scammer_personality' => 'Concerned branch manager, reassuring but urgent, references internal bank systems',
                'urgency_level' => 'critical',
                'emotional_triggers' => ['active theft panic', 'authority figure', 'time-sensitive loss'],
            ],
            [
                'scenario' => 'Fake credit card upgrade offer with "limited time" benefits',
                'hook' => 'Bank is upgrading select customers to a premium card with exclusive benefits - verify now to qualify',
                'progression' => [
                    'scammer_1' => 'Call from "credit card services": selected for platinum card upgrade, 0% APR, $500 bonus, just need to verify current account',
                    'victim_1' => 'Interested in the upgrade, asks about annual fees and benefits',
                    'scammer_2' => 'Lists premium benefits (airport lounge, cashback, travel insurance), needs SSN and current card details to process upgrade',
                    'victim_2' => 'Asks why they cannot visit the branch in person',
                    'scammer_3' => 'Claims offer expires today, phone-only promotion, other customers on waitlist, "just takes 2 minutes"',
                ],
                'scammer_personality' => 'Enthusiastic bank sales representative, friendly, drops exclusive language',
                'urgency_level' => 'medium',
                'emotional_triggers' => ['desire for premium status', 'fear of missing out', 'trust in bank brand'],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function getGovImpersonationTemplates(): array
    {
        return [
            [
                'scenario' => 'Fake IRS tax refund notification with online form',
                'hook' => 'Official notice: tax overpayment refund of $327 to claim within 72 hours',
                'progression' => [
                    'scammer_1' => 'Email styled like IRS.gov: calculated refund of $327.48, click secure link to claim before deadline',
                    'victim_1' => 'Pleasantly surprised, asks if it is normal to receive this by email',
                    'scammer_2' => 'Explains IRS digitalization initiative, form is on "secured IRS portal"',
                    'victim_2' => 'Wants to check on their usual IRS account first',
                    'scammer_3' => 'Refund not yet visible online (processing delay), the form accelerates the process before the window closes',
                ],
                'scammer_personality' => 'IRS agent, neutral administrative tone, cites legal references and form numbers',
                'urgency_level' => 'medium',
                'emotional_triggers' => ['free money', 'government authority', 'bureaucratic deadline'],
            ],
            [
                'scenario' => 'Social Security Administration threatening benefit suspension',
                'hook' => 'Your Social Security number has been compromised and benefits will be suspended unless verified immediately',
                'progression' => [
                    'scammer_1' => 'Automated call then live "agent": SSN linked to criminal activity in Texas, benefits frozen pending investigation',
                    'victim_1' => 'Frightened, says they have never been to Texas, asks how to fix this',
                    'scammer_2' => 'Must verify SSN and recent bank activity to clear the flag, otherwise case goes to federal prosecutor',
                    'victim_2' => 'Asks for a case number to verify with local SSA office',
                    'scammer_3' => 'Provides fake case number, warns that contacting local office will "delay resolution by 6-8 weeks" and benefits remain frozen',
                ],
                'scammer_personality' => 'Federal agent, stern and authoritative, uses legal jargon, references federal law sections',
                'urgency_level' => 'critical',
                'emotional_triggers' => ['fear of arrest', 'loss of benefits', 'government authority'],
            ],
            [
                'scenario' => 'Fake DMV notice requiring immediate online renewal',
                'hook' => 'Driver\'s license suspension notice - renew online immediately to avoid driving penalties',
                'progression' => [
                    'scammer_1' => 'SMS from "DMV": license expires in 48 hours, renew online now to avoid $500 reinstatement fee',
                    'victim_1' => 'Confused, thought license was current, clicks link to check',
                    'scammer_2' => 'Fake DMV portal asks for license number, SSN, and $35 "express renewal fee" via credit card',
                    'victim_2' => 'Hesitates about entering SSN online',
                    'scammer_3' => 'Claims it is federally required for identity verification, "256-bit SSL encryption", renewal will be instant',
                ],
                'scammer_personality' => 'Automated government system, formal language, references state codes',
                'urgency_level' => 'high',
                'emotional_triggers' => ['fear of losing driving privileges', 'penalty avoidance', 'convenience of online renewal'],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function getTechSupportTemplates(): array
    {
        return [
            [
                'scenario' => 'Fake virus alert pop-up with Microsoft tech support scam',
                'hook' => 'Screen locked with "Trojan detected" alert, call support number immediately',
                'progression' => [
                    'scammer_1' => '"Microsoft technician" answers, confirms severe infection, proposes remote desktop access to diagnose',
                    'victim_1' => 'Panicked by frozen screen, asks how much it will cost',
                    'scammer_2' => 'Free diagnostic, installs AnyDesk, runs fake scan showing "127 critical threats"',
                    'victim_2' => 'Worried about data, asks for a solution',
                    'scammer_3' => 'Pro antivirus 5-year license for $299 OR pay with gift cards for "secure transaction processing"',
                ],
                'scammer_personality' => 'Tech support agent with heavy accent, patient but insistent, uses technical jargon to intimidate',
                'urgency_level' => 'critical',
                'emotional_triggers' => ['fear of data loss', 'computer unusable', 'technical authority'],
            ],
            [
                'scenario' => 'Fake Apple iCloud security breach notification',
                'hook' => 'Your iCloud has been compromised - photos and contacts being downloaded by unauthorized user',
                'progression' => [
                    'scammer_1' => 'Pop-up on Safari: "Your iCloud account is being accessed from an unknown device in Beijing. Call Apple Support immediately."',
                    'victim_1' => 'Terrified about personal photos, calls the number',
                    'scammer_2' => '"Apple Senior Advisor" confirms breach, needs Apple ID credentials to "reset security tokens"',
                    'victim_2' => 'Asks if they should change their password themselves',
                    'scammer_3' => 'Claims the hacker has locked the reset mechanism, only Apple tier-2 support can fix remotely, needs TeamViewer access',
                ],
                'scammer_personality' => 'Calm "Apple Genius", uses Apple terminology, sounds professional and empathetic',
                'urgency_level' => 'critical',
                'emotional_triggers' => ['privacy violation', 'personal photos at risk', 'brand trust in Apple'],
            ],
            [
                'scenario' => 'Fake ISP calling about "compromised router" spreading malware',
                'hook' => 'Your home router is infected and sending spam to thousands of addresses - ISP must intervene',
                'progression' => [
                    'scammer_1' => 'Call from "ISP security team": router firmware compromised, sending 50K spam emails/day, account will be terminated unless fixed now',
                    'victim_1' => 'Alarmed, asks what they should do, has no technical knowledge',
                    'scammer_2' => 'Needs remote access to router admin panel, asks for current WiFi password and router model',
                    'victim_2' => 'Gives WiFi name but hesitates on password',
                    'scammer_3' => 'Claims without password they cannot patch the firmware, account termination in 24 hours, cites "Terms of Service section 8.3"',
                ],
                'scammer_personality' => 'ISP network engineer, uses networking terminology, polite but matter-of-fact',
                'urgency_level' => 'high',
                'emotional_triggers' => ['fear of losing internet', 'technical ignorance', 'legal threats'],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function getRomanceScamTemplates(): array
    {
        return [
            [
                'scenario' => 'Romance scam - Oil rig engineer stranded abroad',
                'hook' => 'After weeks of romantic online exchanges, a sudden medical/financial emergency abroad',
                'progression' => [
                    'scammer_1' => 'After weeks of sweet messages, announces accident on oil rig in Ghana, hospitalized, needs $3,500 bail/medical deposit',
                    'victim_1' => 'Shocked and worried, asks for details, offers to help',
                    'scammer_2' => 'Dramatic detailed account, exact amount needed ($3,500), promises repayment once released from hospital',
                    'victim_2' => 'Hesitates about the amount, suggests a smaller sum or family loan',
                    'scammer_3' => 'Emotional manipulation, references shared feelings, promises future meeting, Western Union is the only option',
                ],
                'scammer_personality' => 'Romantic, vulnerable, excellent communicator, patient long-term grooming',
                'urgency_level' => 'medium',
                'emotional_triggers' => ['love/attachment', 'guilt', 'promise of future together', 'loved one in distress'],
            ],
            [
                'scenario' => 'Romance scam - Military officer deployed overseas',
                'hook' => 'US Army captain on peacekeeping mission needs help with "leave application fees"',
                'progression' => [
                    'scammer_1' => 'After a month of daily messaging, says military leave was approved but requires $2,800 processing fee that the Army "doesn\'t cover"',
                    'victim_1' => 'Excited about meeting, but confused why the Army charges for leave',
                    'scammer_2' => 'Explains it\'s a UN peacekeeping rule, not regular Army, shares fake document with official-looking letterhead',
                    'victim_2' => 'Asks if there is another way, offers to contact the base directly',
                    'scammer_3' => 'Claims base communications are restricted for security, only family wire transfers accepted, "I\'ve never asked anyone for money before"',
                ],
                'scammer_personality' => 'Honorable soldier, humble, emotionally reserved but deeply affectionate in private',
                'urgency_level' => 'medium',
                'emotional_triggers' => ['patriotism', 'romantic devotion', 'once-in-a-lifetime meeting', 'duty and sacrifice'],
            ],
            [
                'scenario' => 'Romance scam - Wealthy businesswoman stuck in customs',
                'hook' => 'Successful entrepreneur\'s luxury goods seized at customs, needs temporary loan for release fees',
                'progression' => [
                    'scammer_1' => 'After intense online relationship, claims to be shipping a "gift package" worth $50K that got stuck in customs, needs $1,200 clearance fee',
                    'victim_1' => 'Flattered by expensive gift, asks about customs process',
                    'scammer_2' => 'Shows fake customs receipt, package contains jewelry and cash "for their future together", fee must be paid by recipient',
                    'victim_2' => 'Suspicious about paying to receive a gift, asks to speak with customs office',
                    'scammer_3' => 'Provides fake customs "agent" phone number, emotional pressure: "Don\'t you trust me? This is our future."',
                ],
                'scammer_personality' => 'Glamorous, generous, slightly dramatic, uses affectionate nicknames constantly',
                'urgency_level' => 'low',
                'emotional_triggers' => ['greed for luxury gift', 'romantic trust test', 'sunk cost of relationship'],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function getAdvanceFeeTemplates(): array
    {
        return [
            [
                'scenario' => 'Unexpected inheritance from a distant relative in Africa',
                'hook' => 'Lawyer announces $8.5M inheritance, requires advance fee for release',
                'progression' => [
                    'scammer_1' => 'Email from South African lawyer: deceased relative left $8.5M estate, searching for heir with matching surname',
                    'victim_1' => 'Skeptical, asks for proof of relation',
                    'scammer_2' => 'Impressive scanned legal documents, family tree, court filing numbers, explains probate urgency',
                    'victim_2' => 'Growing interest, asks about the process',
                    'scammer_3' => 'Notary/bank fees of $4,500 must be advanced, fully refundable from inheritance, "once-in-a-lifetime" chance',
                ],
                'scammer_personality' => 'Distinguished international lawyer, formal language, abundant documentation',
                'urgency_level' => 'low',
                'emotional_triggers' => ['easy money', 'unique opportunity', 'apparent legitimacy'],
            ],
            [
                'scenario' => 'Lottery winner notification - you\'ve been selected',
                'hook' => 'Your email was randomly selected in the Microsoft/Google Annual Lottery, prize is $2.5M',
                'progression' => [
                    'scammer_1' => 'Official-looking email: congratulations on winning $2,500,000 in the "International Email Lottery", reference number provided',
                    'victim_1' => 'Excited but suspicious, asks how they entered without knowing',
                    'scammer_2' => 'Explains email addresses are harvested from internet users, prize is legitimate, provides "claim agent" contact',
                    'victim_2' => 'Asks why they need to pay anything to receive winnings',
                    'scammer_3' => 'Processing fee of $950 covers "international transfer tax and insurance", government regulation, prize much larger than fee',
                ],
                'scammer_personality' => 'Official lottery administrator, bureaucratic, references legal frameworks',
                'urgency_level' => 'medium',
                'emotional_triggers' => ['windfall excitement', 'small fee vs. huge prize logic', 'official-sounding process'],
            ],
            [
                'scenario' => 'Stranded traveler scam - hacked friend needs emergency funds',
                'hook' => 'Email from friend\'s hacked account: robbed while traveling, needs emergency wire transfer',
                'progression' => [
                    'scammer_1' => 'Email from "friend": mugged in London, passport and wallet stolen, embassy can\'t help until Monday, needs $800 for hotel and emergency flight',
                    'victim_1' => 'Concerned about friend, asks if they called the police',
                    'scammer_2' => 'Police report filed but it takes days, using hotel business center computer, phone stolen too, please wire money urgently',
                    'victim_2' => 'Asks for a phone number to call them directly',
                    'scammer_3' => 'Phone was stolen, can only communicate by email, hotel checkout is tomorrow morning, "you\'re the only person I trust"',
                ],
                'scammer_personality' => 'Distressed friend, informal language matching friend\'s style, emotional appeals',
                'urgency_level' => 'high',
                'emotional_triggers' => ['friendship loyalty', 'emergency situation', 'guilt if friend suffers'],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function getInvestmentScamTemplates(): array
    {
        return [
            [
                'scenario' => 'Crypto trading platform with guaranteed returns and signup bonus',
                'hook' => 'AI-powered crypto investment opportunity with guaranteed 15% monthly returns and $200 signup bonus',
                'progression' => [
                    'scammer_1' => 'Targeted ad then personal "advisor": platform demo, testimonials of gains, limited-time $200 signup bonus',
                    'victim_1' => 'Intrigued, asks about guarantees and how it works',
                    'scammer_2' => 'Explains proprietary AI algorithm, shows fake regulatory license, demo account shows quick profits',
                    'victim_2' => 'Wants to start with a small amount ($500)',
                    'scammer_3' => 'Agrees, profits appear quickly on dashboard, suggests increasing investment to maximize returns',
                ],
                'scammer_personality' => 'Professional financial advisor, enthusiastic but not pushy, precise numbers and charts',
                'urgency_level' => 'low',
                'emotional_triggers' => ['greed', 'fear of missing out', 'trust in numbers', 'social proof'],
            ],
            [
                'scenario' => 'Forex trading mentorship with WhatsApp group showing daily profits',
                'hook' => 'Join exclusive forex mentorship group - mentor shows daily $5K+ profits, only 10 spots remaining',
                'progression' => [
                    'scammer_1' => 'Instagram DM: "I made $47K this month trading forex, want me to teach you? Free trial in our VIP group"',
                    'victim_1' => 'Skeptical but curious, asks about the catch',
                    'scammer_2' => 'Adds to WhatsApp group with 200+ members posting "gains", mentor does live trading, $997 mentorship fee for "lifetime access"',
                    'victim_2' => 'Asks if they can try with demo first',
                    'scammer_3' => 'Demo shows massive gains, but "real profits only with real money", VIP mentorship price going up to $2,997 next week',
                ],
                'scammer_personality' => 'Young successful trader, flashy lifestyle, motivational speaker vibes, uses emojis heavily',
                'urgency_level' => 'medium',
                'emotional_triggers' => ['lifestyle envy', 'get-rich-quick desire', 'community belonging', 'scarcity of spots'],
            ],
            [
                'scenario' => 'Pig butchering - long-term relationship building before investment push',
                'hook' => 'New online acquaintance gradually reveals their "secret" investment platform after building trust over weeks',
                'progression' => [
                    'scammer_1' => 'After weeks of casual texting and friendship building, casually mentions making money on a "private investment platform"',
                    'victim_1' => 'Curious, asks what kind of platform',
                    'scammer_2' => 'Shows screenshots of account balance growing, offers to "teach" step by step, starts with just $200',
                    'victim_2' => 'Deposits $200, sees it "grow" to $350 on the fake platform',
                    'scammer_3' => 'Encourages larger deposit for "premium tier" access with higher returns, suggests $5,000-$10,000',
                ],
                'scammer_personality' => 'Friendly acquaintance, patient, shares personal stories, never pushes too hard',
                'urgency_level' => 'low',
                'emotional_triggers' => ['trust built over time', 'small initial success', 'friendship leverage', 'gradual escalation'],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function getDeliveryScamTemplates(): array
    {
        return [
            [
                'scenario' => 'Fake FedEx/UPS SMS - package held at customs',
                'hook' => 'Package awaiting delivery, $2.99 customs fee required within 48 hours',
                'progression' => [
                    'scammer_1' => 'SMS from "FedEx": international package held at customs, pay $2.99 processing fee online to release',
                    'victim_1' => 'Not expecting a package but curious, clicks link',
                    'scammer_2' => 'Convincing clone site with tracking number and package details, credit card form for $2.99',
                    'victim_2' => 'Hesitates to enter card details for such a small amount',
                    'scammer_3' => 'No other payment method available, package returns to sender after 48h, small fee for standard customs processing',
                ],
                'scammer_personality' => 'Automated system then neutral customer service, procedural and impersonal',
                'urgency_level' => 'medium',
                'emotional_triggers' => ['package curiosity', 'small amount seems harmless', 'time pressure'],
            ],
            [
                'scenario' => 'Fake Amazon delivery failure requiring address update',
                'hook' => 'Your Amazon order could not be delivered - update shipping address to redeliver',
                'progression' => [
                    'scammer_1' => 'Email from "Amazon Logistics": delivery attempt failed, click to update address and reschedule within 24 hours',
                    'victim_1' => 'Checks recent orders, not sure which package it is, clicks to investigate',
                    'scammer_2' => 'Fake Amazon login page asks for credentials to "access delivery management dashboard"',
                    'victim_2' => 'Enters email but pauses at password, notices URL looks different',
                    'scammer_3' => 'Pop-up says "session expired for security", asks to re-enter credentials, adds "verify payment method" step',
                ],
                'scammer_personality' => 'Amazon-style automated messaging, clean design, professional copywriting',
                'urgency_level' => 'medium',
                'emotional_triggers' => ['expecting a real package', 'Amazon brand trust', 'convenience of quick fix'],
            ],
            [
                'scenario' => 'Fake USPS "missed delivery" notice with QR code',
                'hook' => 'Physical notice left at door: scan QR code to reschedule delivery of "certified mail"',
                'progression' => [
                    'scammer_1' => 'Physical card left at door looks like USPS missed delivery notice, QR code leads to phishing site',
                    'victim_1' => 'Scans QR code, sees professional-looking USPS site asking for personal details',
                    'scammer_2' => 'Site requests full name, address, phone, and $1.50 "redelivery scheduling fee"',
                    'victim_2' => 'Enters name and address but pauses at payment',
                    'scammer_3' => 'Timer on screen shows "redelivery window closing", "your certified mail may be returned to court/IRS"',
                ],
                'scammer_personality' => 'Official USPS branding, bureaucratic language, implied government importance',
                'urgency_level' => 'high',
                'emotional_triggers' => ['certified mail implies legal importance', 'tiny fee', 'fear of missing official documents'],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function getInvoiceFraudTemplates(): array
    {
        return [
            [
                'scenario' => 'Intercepted email thread - real invoice with modified bank details',
                'hook' => 'Legitimate vendor invoice in an existing email thread but with bank details swapped via email compromise',
                'progression' => [
                    'scammer_1' => 'Existing email thread, authentic-looking PDF invoice but bank account number changed in attachment',
                    'victim_1' => 'Processes invoice normally, prepares wire transfer',
                    'scammer_2' => 'If questioned, sends "follow-up" email with same fake bank details',
                    'victim_2' => 'Completes payment without suspicion',
                    'scammer_3' => 'Real vendor contacts about unpaid invoice days later, fraud discovered',
                ],
                'scammer_personality' => 'Invisible (email compromise), or vendor accountant persona if contacted',
                'urgency_level' => 'low',
                'emotional_triggers' => ['routine processing', 'established business trust'],
            ],
            [
                'scenario' => 'Fake software license renewal invoice from "Microsoft" or "Adobe"',
                'hook' => 'Annual software renewal invoice for $499 - pay now or lose access to critical business tools',
                'progression' => [
                    'scammer_1' => 'Invoice email from "Microsoft Licensing" for annual Office 365 renewal, $499.99, payment due in 7 days',
                    'victim_1' => 'Forwards to IT, asks if this is a legitimate renewal',
                    'scammer_2' => 'Follow-up "reminder" with late fee warning, references company name and correct number of user licenses',
                    'victim_2' => 'IT department looks into it, asks for vendor account number',
                    'scammer_3' => 'Provides fake account portal link, "past due" notice with threat of service interruption next Monday',
                ],
                'scammer_personality' => 'Corporate billing department, formal invoicing language, escalation warnings',
                'urgency_level' => 'medium',
                'emotional_triggers' => ['fear of service disruption', 'routine payment', 'corporate brand authority'],
            ],
            [
                'scenario' => 'Overdue invoice from fake legal firm threatening collections',
                'hook' => 'Law firm sends "final notice" for unpaid consulting invoice, threatening credit report damage',
                'progression' => [
                    'scammer_1' => 'Letter-style email from "Henderson & Associates LLP": final notice for invoice #INV-2024-3847, $3,200 overdue, collections action in 5 days',
                    'victim_1' => 'Confused, doesn\'t recognize the firm, asks for details about the services provided',
                    'scammer_2' => 'References vague "consulting services" from 6 months ago, attaches fake contract with forged signature',
                    'victim_2' => 'Denies having engaged any such services, threatens to involve their own lawyer',
                    'scammer_3' => 'Offers "settlement" at 50% discount ($1,600) to "avoid court costs and credit damage", 48-hour window',
                ],
                'scammer_personality' => 'Aggressive collections attorney, formal legal language, implies severe consequences',
                'urgency_level' => 'high',
                'emotional_triggers' => ['fear of legal action', 'credit score damage', 'settlement seems like a deal'],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function getGenericTemplates(): array
    {
        return [
            [
                'scenario' => 'Generic email scam with suspicious offer',
                'hook' => 'Unexpected offer or unusual request by email',
                'progression' => [
                    'scammer_1' => 'Initial email with an enticing proposition or alarming alert',
                    'victim_1' => 'Cautious response, asks clarifying questions',
                    'scammer_2' => 'Attempts to convince with additional details and false credibility markers',
                ],
                'scammer_personality' => 'Variable depending on context',
                'urgency_level' => 'medium',
                'emotional_triggers' => ['curiosity', 'opportunity'],
            ],
            [
                'scenario' => 'Social media prize scam via direct message',
                'hook' => 'You\'ve won a giveaway, DM us your details to claim your prize',
                'progression' => [
                    'scammer_1' => 'DM from brand impersonator: "Congratulations! You won our monthly giveaway. Reply with your email and shipping address."',
                    'victim_1' => 'Excited, asks what they won and when it was announced',
                    'scammer_2' => 'Claims it was announced on Stories, needs shipping address plus $9.99 "handling fee" via PayPal or Venmo',
                ],
                'scammer_personality' => 'Enthusiastic social media manager, casual tone, uses hashtags and emojis',
                'urgency_level' => 'low',
                'emotional_triggers' => ['winning excitement', 'brand trust', 'small fee seems reasonable'],
            ],
            [
                'scenario' => 'Charity scam after natural disaster',
                'hook' => 'Donate now to help disaster victims - every dollar counts',
                'progression' => [
                    'scammer_1' => 'Email referencing real disaster: "Help the victims of [recent event]. Your donation saves lives. Donate securely here."',
                    'victim_1' => 'Wants to help, asks if the charity is registered and tax-deductible',
                    'scammer_2' => 'Provides fake charity registration number, emotional photos, "100% of donations go directly to victims"',
                ],
                'scammer_personality' => 'Compassionate charity worker, emotional language, urgency about suffering',
                'urgency_level' => 'medium',
                'emotional_triggers' => ['compassion', 'guilt', 'desire to help', 'social pressure'],
            ],
        ];
    }
}

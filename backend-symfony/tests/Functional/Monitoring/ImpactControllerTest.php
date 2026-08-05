<?php

declare(strict_types=1);

namespace Tests\Functional\Monitoring;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ImpactControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    // --- Summary: Auth ---

    public function testSummaryRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/impact/summary');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    // --- Summary: Structure ---

    public function testSummaryReturnsExpectedStructure(): void
    {
        $data = $this->authenticatedGet('/api/v1/impact/summary');
        $this->assertArrayHasKey('wasted_time', $data);
        $this->assertArrayHasKey('ioc_value', $data);
        $this->assertArrayHasKey('cost_efficiency', $data);
        $this->assertArrayHasKey('campaigns', $data);
    }

    public function testSummaryWastedTimeHasCorrectKeys(): void
    {
        $data = $this->authenticatedGet('/api/v1/impact/summary');
        $wt = $data['wasted_time'];
        $this->assertArrayHasKey('total_hours', $wt);
        $this->assertArrayHasKey('total_conversations', $wt);
        $this->assertArrayHasKey('avg_hours', $wt);
        $this->assertArrayHasKey('weekly_trend', $wt);
        // Scammer Replies Elicited tile keys
        $this->assertArrayHasKey('scammer_replies_count', $wt);
        $this->assertArrayHasKey('scammer_replies_prev_count', $wt);
        $this->assertArrayHasKey('scammer_replies_delta_pct', $wt);
    }

    /**
     * scammer_replies_count is null-safe per period, has
     * the right type, and prev/delta nullify on period=all.
     */
    public function testScammerRepliesFieldsHaveCorrectTypesAndPeriodSemantics(): void
    {
        // Windowed period: all 3 fields populated (delta may still be
        // null if prev window is empty on the seed data — but the
        // structure must be int/int|null/float|null).
        $windowed = $this->authenticatedGet('/api/v1/impact/summary?period=30d')['wasted_time'];
        $this->assertIsInt($windowed['scammer_replies_count']);
        $this->assertGreaterThanOrEqual(0, $windowed['scammer_replies_count']);

        if ($windowed['scammer_replies_prev_count'] !== null) {
            $this->assertIsInt($windowed['scammer_replies_prev_count']);
        }
        if ($windowed['scammer_replies_delta_pct'] !== null) {
            $this->assertIsNumeric($windowed['scammer_replies_delta_pct']);
        }

        // period=all: count populated, prev + delta are null (the
        // "vs previous period" framing doesn't apply on All).
        $all = $this->authenticatedGet('/api/v1/impact/summary?period=all')['wasted_time'];
        $this->assertIsInt($all['scammer_replies_count']);
        $this->assertNull($all['scammer_replies_prev_count']);
        $this->assertNull($all['scammer_replies_delta_pct']);
    }

    /**
     * Qualified-conversation filter: only convs where the
     * scammer actually replied (turns_count >= 2) feed the headline.
     * Seeds one 1-turn (excluded) and one 3-turn (included) conv inside
     * a DAMA-wrapped transaction so the asserted deltas are deterministic.
     */
    public function testWastedTimeExcludesSingleTurnConversations(): void
    {
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = static::getContainer()->get('doctrine.dbal.default_connection');

        $baseline = $this->authenticatedGet('/api/v1/impact/summary')['wasted_time'];
        $baselineConvs = (int) $baseline['total_conversations'];
        $baselineHours = (float) $baseline['total_hours'];
        $baselineReplies = (int) $baseline['scammer_replies_count'];

        $scamTypeId = $conn->fetchOne('SELECT scam_type_id FROM lkp_scam_type ORDER BY scam_type_id LIMIT 1');
        $channelId = $conn->fetchOne('SELECT channel_id FROM lkp_channel ORDER BY channel_id LIMIT 1');
        $accountId = $conn->fetchOne('SELECT account_id FROM mail_account ORDER BY created_at LIMIT 1');
        $dirInId = $conn->fetchOne("SELECT dir_id FROM lkp_direction WHERE code = 'in'");
        $dirOutId = $conn->fetchOne("SELECT dir_id FROM lkp_direction WHERE code = 'out'");

        if ($scamTypeId === false || $channelId === false || $accountId === false || $dirInId === false || $dirOutId === false) {
            self::markTestSkipped('Missing fixture (scam_type/channel/mail_account/direction).');
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // 1-turn conv (scammer's first email only, no reply) — must be excluded.
        $oneTurnId = '11111111-1111-4111-8111-aaaaaaaaaaaa';
        $conn->insert('conversation', [
            'conv_id' => $oneTurnId,
            'primary_channel_id' => $channelId,
            'scam_type_id' => $scamTypeId,
            'account_id' => $accountId,
            'status' => 'closed',
            'score_risk' => 50,
            'engagement_duration_sec' => 0,
            'turns_count' => 1,
            'ts_first' => $now,
            'ts_last' => $now,
            'tlp' => 'AMBER',
            'delivery' => 'api',
            'stix_id' => 'demo-spec107-onturn',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        // The 1-turn conv has 1 inbound msg — must NOT be counted because
        // the conversation is below the turns_count >= 2 filter.
        $conn->insert('message', [
            'msg_id' => '22222222-1111-4111-8111-aaaaaaaaaaaa',
            'conv_id' => $oneTurnId,
            'channel_id' => $channelId,
            'direction' => $dirInId,
            'lang_detect' => 'en',
            'subject' => 'spec108 ignore me',
            'body_text' => 'inbound-ignored',
            'headers' => '{}',
            'composite_hash' => hash('sha256', $oneTurnId . ':0'),
            'ts_msg' => $now,
            'ts_ingest' => $now,
        ]);

        // 3-turn conv with 1h engagement — must be included; scammer
        // sent 2 inbound msgs, we sent 1 outbound (turns_count = 3).
        $threeTurnId = '11111111-1111-4111-8111-bbbbbbbbbbbb';
        $conn->insert('conversation', [
            'conv_id' => $threeTurnId,
            'primary_channel_id' => $channelId,
            'scam_type_id' => $scamTypeId,
            'account_id' => $accountId,
            'status' => 'closed',
            'score_risk' => 50,
            'engagement_duration_sec' => 3600,
            'turns_count' => 3,
            'ts_first' => $now,
            'ts_last' => $now,
            'tlp' => 'AMBER',
            'delivery' => 'api',
            'stix_id' => 'demo-spec107-threeturn',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ([['1', $dirInId], ['2', $dirOutId], ['3', $dirInId]] as [$seq, $dir]) {
            $conn->insert('message', [
                'msg_id' => '22222222-2222-4222-8222-bbbbbbbbbbb' . $seq,
                'conv_id' => $threeTurnId,
                'channel_id' => $channelId,
                'direction' => $dir,
                'lang_detect' => 'en',
                'subject' => 'spec108 turn ' . $seq,
                'body_text' => 'multi-turn',
                'headers' => '{}',
                'composite_hash' => hash('sha256', $threeTurnId . ':' . $seq),
                'ts_msg' => $now,
                'ts_ingest' => $now,
            ]);
        }

        $after = $this->authenticatedGet('/api/v1/impact/summary')['wasted_time'];

        self::assertSame($baselineConvs + 1, (int) $after['total_conversations'], 'Only the multi-turn conv must enter total_conversations.');
        self::assertEqualsWithDelta($baselineHours + 1.0, (float) $after['total_hours'], 0.01, 'The 1h multi-turn conv adds 1h; the 1-turn conv contributes 0.');
        self::assertSame($baselineReplies + 2, (int) $after['scammer_replies_count'], 'Two inbound msgs from the 3-turn conv must count; the 1-turn conv inbound msg must NOT (below turns_count >= 2 filter).');
    }

    public function testSummaryIocValueHasCorrectKeys(): void
    {
        $data = $this->authenticatedGet('/api/v1/impact/summary');
        $ioc = $data['ioc_value'];
        $this->assertArrayHasKey('total_iocs', $ioc);
        $this->assertArrayHasKey('novel_iocs', $ioc);
        $this->assertArrayHasKey('novel_pct', $ioc);
        $this->assertArrayHasKey('financial_iocs', $ioc);
        $this->assertArrayHasKey('by_type', $ioc);
        // Fresh IOCs tile keys (window follows period selector)
        $this->assertArrayHasKey('fresh_iocs_count', $ioc);
        $this->assertArrayHasKey('fresh_iocs_prev_count', $ioc);
        $this->assertArrayHasKey('fresh_iocs_delta_pct', $ioc);
        $this->assertArrayHasKey('fresh_iocs_window_days', $ioc);
    }

    /**
     * When a window applies (period != all), all fresh fields
     * are populated with the expected types.
     */
    public function testSummaryFreshIocsFieldsHaveCorrectTypesForWindowedPeriod(): void
    {
        $data = $this->authenticatedGet('/api/v1/impact/summary?period=30d');
        $ioc = $data['ioc_value'];

        $this->assertSame(30, $ioc['fresh_iocs_window_days']);
        $this->assertIsInt($ioc['fresh_iocs_count']);
        $this->assertGreaterThanOrEqual(0, $ioc['fresh_iocs_count']);
        $this->assertIsInt($ioc['fresh_iocs_prev_count']);
        $this->assertGreaterThanOrEqual(0, $ioc['fresh_iocs_prev_count']);

        // null is allowed (and expected) when prev window is empty
        if ($ioc['fresh_iocs_delta_pct'] !== null) {
            $this->assertIsNumeric($ioc['fresh_iocs_delta_pct']);
        }
    }

    /**
     * On period=all, the tile switches to its cumulative
     * "Total IOCs" face. Backend signals this by nulling all fresh_*
     * fields; total_iocs still carries the cumulative count.
     */
    public function testSummaryFreshIocsFieldsAreNullOnPeriodAll(): void
    {
        $data = $this->authenticatedGet('/api/v1/impact/summary?period=all');
        $ioc = $data['ioc_value'];

        $this->assertNull($ioc['fresh_iocs_window_days']);
        $this->assertNull($ioc['fresh_iocs_count']);
        $this->assertNull($ioc['fresh_iocs_prev_count']);
        $this->assertNull($ioc['fresh_iocs_delta_pct']);

        // total_iocs is still the cumulative count, used by the
        // "Total IOCs" face of the tile.
        $this->assertIsInt($ioc['total_iocs']);
        $this->assertGreaterThanOrEqual(0, $ioc['total_iocs']);
    }

    /**
     * Period selector drives the window.
     *
     * @dataProvider periodWindowProvider
     */
    public function testFreshIocsWindowFollowsPeriodSelector(string $period, ?int $expectedDays): void
    {
        $data = $this->authenticatedGet('/api/v1/impact/summary?period=' . $period);
        $this->assertSame($expectedDays, $data['ioc_value']['fresh_iocs_window_days']);
    }

    /**
     * @return iterable<string, array{string, int|null}>
     */
    public static function periodWindowProvider(): iterable
    {
        yield '7d period → 7-day window' => ['7d', 7];
        yield '30d period → 30-day window' => ['30d', 30];
        yield '90d period → 90-day window' => ['90d', 90];
        yield 'all period → null (Total IOCs face)' => ['all', null];
    }

    public function testSummaryCostHasCorrectKeys(): void
    {
        $data = $this->authenticatedGet('/api/v1/impact/summary');
        $cost = $data['cost_efficiency'];
        $this->assertArrayHasKey('total_cost_usd', $cost);
        $this->assertArrayHasKey('cost_per_ioc_usd', $cost);
    }

    public function testSummaryCampaignsHasCorrectKeys(): void
    {
        $data = $this->authenticatedGet('/api/v1/impact/summary');
        $campaigns = $data['campaigns'];
        $this->assertArrayHasKey('total', $campaigns);
        $this->assertArrayHasKey('promoted', $campaigns);
        $this->assertArrayHasKey('top_campaigns', $campaigns);
    }

    // --- Summary: Periods ---

    public function testSummaryWithPeriod7d(): void
    {
        $data = $this->authenticatedGet('/api/v1/impact/summary?period=7d');
        $this->assertArrayHasKey('wasted_time', $data);
        $this->assertArrayHasKey('ioc_value', $data);
    }

    public function testSummaryWithPeriodAll(): void
    {
        $data = $this->authenticatedGet('/api/v1/impact/summary?period=all');
        $this->assertArrayHasKey('wasted_time', $data);
        $this->assertArrayHasKey('ioc_value', $data);
    }

    // --- IOC Uniqueness: Auth ---

    public function testIocUniquenessRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/impact/ioc-uniqueness');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    // --- IOC Uniqueness: Structure ---

    public function testIocUniquenessReturnsStructure(): void
    {
        $data = $this->authenticatedGet('/api/v1/impact/ioc-uniqueness');
        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('by_type', $data);
    }

    public function testIocUniquenessWithTypeFilter(): void
    {
        $data = $this->authenticatedGet('/api/v1/impact/ioc-uniqueness?ioc_type=url');
        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('by_type', $data);
    }

    // --- Summary: Content-Type ---

    public function testSummaryReturnsJsonContentType(): void
    {
        $this->client->request('GET', '/api/v1/impact/summary', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $contentType = $this->client->getResponse()->headers->get('Content-Type');
        $this->assertNotNull($contentType);
        $this->assertStringContainsString('json', $contentType);
    }

    // --- Summary: Numeric checks ---

    public function testSummaryNovelPctIsNumeric(): void
    {
        $data = $this->authenticatedGet('/api/v1/impact/summary');
        $novelPct = $data['ioc_value']['novel_pct'];
        $this->assertIsNumeric($novelPct);
        $this->assertNotNull($novelPct);
    }

    public function testSummaryCostPerIocIsNumeric(): void
    {
        $data = $this->authenticatedGet('/api/v1/impact/summary');
        $costPerIoc = $data['cost_efficiency']['cost_per_ioc_usd'];
        $this->assertIsNumeric($costPerIoc);
        $this->assertNotNull($costPerIoc);
    }

    // === scam_type filter tests ===

    public function testSummaryAcceptsScamTypeFilter_096C2(): void
    {
        $data = $this->authenticatedGet('/api/v1/impact/summary?scam_type=INVOICE_FRAUD');
        // Endpoint must return a valid structure (filtered or not)
        $this->assertArrayHasKey('wasted_time', $data);
        $this->assertArrayHasKey('ioc_value', $data);
        $this->assertArrayHasKey('cost_efficiency', $data);
        $this->assertArrayHasKey('campaigns', $data);
        // Numeric metrics remain non-negative
        $this->assertGreaterThanOrEqual(0, $data['wasted_time']['total_conversations']);
        $this->assertGreaterThanOrEqual(0, $data['ioc_value']['total_iocs']);
    }

    public function testSummaryWithScamTypeAndPeriodCombined_096C2(): void
    {
        // date + scam_type filters MUST combine, not override each other.
        $data = $this->authenticatedGet('/api/v1/impact/summary?period=30d&scam_type=PHISHING');
        $this->assertArrayHasKey('wasted_time', $data);
        $this->assertIsInt($data['wasted_time']['total_conversations']);
    }

    public function testSummaryEmptyScamTypeBehavesAsNoFilter_096C2(): void
    {
        $baseline = $this->authenticatedGet('/api/v1/impact/summary');
        $withEmpty = $this->authenticatedGet('/api/v1/impact/summary?scam_type=');
        // Empty string scam_type must be treated as null (no filter) — byte-identical response
        $this->assertSame(
            $baseline['wasted_time']['total_conversations'],
            $withEmpty['wasted_time']['total_conversations'],
        );
        $this->assertSame(
            $baseline['ioc_value']['total_iocs'],
            $withEmpty['ioc_value']['total_iocs'],
        );
    }

    public function testSummaryScamTypeFilterReducesOrEqualsBaseline_096C2(): void
    {
        $baseline = $this->authenticatedGet('/api/v1/impact/summary');
        $filtered = $this->authenticatedGet('/api/v1/impact/summary?scam_type=INVOICE_FRAUD');
        // A specific scam_type filter NEVER returns more conversations than the unfiltered set
        $this->assertLessThanOrEqual(
            $baseline['wasted_time']['total_conversations'],
            $filtered['wasted_time']['total_conversations'],
        );
    }

    // === scam_type filter on IocUniqueness ===

    public function testIocUniquenessAcceptsScamTypeFilter_096C3(): void
    {
        $data = $this->authenticatedGet('/api/v1/impact/ioc-uniqueness?scam_type=INVOICE_FRAUD');
        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('by_type', $data);
        $this->assertArrayHasKey('daily_trend', $data);
        $this->assertGreaterThanOrEqual(0, $data['summary']['total_iocs']);
    }

    public function testIocUniquenessScamTypeFilterReducesOrEquals_096C3(): void
    {
        $baseline = $this->authenticatedGet('/api/v1/impact/ioc-uniqueness');
        $filtered = $this->authenticatedGet('/api/v1/impact/ioc-uniqueness?scam_type=PHISHING');
        $this->assertLessThanOrEqual(
            $baseline['summary']['total_iocs'],
            $filtered['summary']['total_iocs'],
        );
    }

    public function testIocUniquenessScamTypeAndPeriodCombine_096C3(): void
    {
        $data = $this->authenticatedGet('/api/v1/impact/ioc-uniqueness?period=30d&scam_type=INVOICE_FRAUD');
        $this->assertArrayHasKey('summary', $data);
    }

    public function testIocUniquenessEmptyScamTypeBehavesAsNoFilter_096C3(): void
    {
        $baseline = $this->authenticatedGet('/api/v1/impact/ioc-uniqueness');
        $withEmpty = $this->authenticatedGet('/api/v1/impact/ioc-uniqueness?scam_type=');
        $this->assertSame(
            $baseline['summary']['total_iocs'],
            $withEmpty['summary']['total_iocs'],
        );
    }

    // === chart trends respect the period filter ===

    public function testSummaryWeeklyTrendRespectsPeriod_096C5(): void
    {
        // With period=7d, weekly_trend rows must all fall within the 7-day window.
        // We don't assert exact row count (depends on fixtures) — only that the response is well-formed.
        $data = $this->authenticatedGet('/api/v1/impact/summary?period=7d');
        $weeklyTrend = $data['wasted_time']['weekly_trend'];
        $this->assertIsArray($weeklyTrend);
        // 7-day window NEVER yields MORE rows than the full 12-week default
        $baseline = $this->authenticatedGet('/api/v1/impact/summary');
        $this->assertLessThanOrEqual(\count($baseline['wasted_time']['weekly_trend']), \count($weeklyTrend));
    }

    public function testIocUniquenessDailyTrendRespectsPeriod_096C5(): void
    {
        // Same regression on the daily_trend chart of /impact/ioc-uniqueness.
        $data7d = $this->authenticatedGet('/api/v1/impact/ioc-uniqueness?period=7d');
        $data30d = $this->authenticatedGet('/api/v1/impact/ioc-uniqueness?period=30d');
        // 7-day window NEVER has more daily points than 30-day window
        $this->assertLessThanOrEqual(\count($data30d['daily_trend']), \count($data7d['daily_trend']));
    }

    /**
     * @return array<string, mixed>
     */
    private function authenticatedGet(string $url): array
    {
        $this->client->request('GET', $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        $this->assertIsString($content);

        $data = json_decode($content, true);
        $this->assertIsArray($data);

        return $data;
    }
}

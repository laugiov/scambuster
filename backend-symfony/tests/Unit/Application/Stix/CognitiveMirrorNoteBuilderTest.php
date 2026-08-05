<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Stix;

use App\Application\Scambaiting\PersonaMirrorReaderInterface;
use App\Application\Stix\CognitiveMirrorNoteBuilder;
use PHPUnit\Framework\TestCase;

final class CognitiveMirrorNoteBuilderTest extends TestCase
{
    public function testReturnsNullWhenNoMirrorRowMatchesScamType(): void
    {
        $mirrors = $this->createMock(PersonaMirrorReaderInterface::class);
        $mirrors->method('getByPersona')->with('lonely_person')->willReturn([
            $this->makeMirror('PHISHING'),
        ]);

        $builder = new CognitiveMirrorNoteBuilder($mirrors);
        $note = $builder->build('threat-actor--abc', 'lonely_person', 'ROMANCE');

        self::assertNull($note);
    }

    public function testReturnsNullWhenServiceReturnsNoRowsAtAll(): void
    {
        $mirrors = $this->createMock(PersonaMirrorReaderInterface::class);
        $mirrors->method('getByPersona')->willReturn([]);

        $builder = new CognitiveMirrorNoteBuilder($mirrors);
        $note = $builder->build('threat-actor--abc', 'lonely_person', 'ROMANCE');

        self::assertNull($note);
    }

    public function testReturnsNullForEmptyInputs(): void
    {
        $mirrors = $this->createMock(PersonaMirrorReaderInterface::class);
        $mirrors->expects(self::never())->method('getByPersona');

        $builder = new CognitiveMirrorNoteBuilder($mirrors);

        self::assertNull($builder->build('', 'lonely_person', 'ROMANCE'));
        self::assertNull($builder->build('threat-actor--abc', '', 'ROMANCE'));
        self::assertNull($builder->build('threat-actor--abc', 'lonely_person', ''));
    }

    public function testBuildsCompleteNoteSdoWhenMirrorMatches(): void
    {
        $mirrors = $this->createMock(PersonaMirrorReaderInterface::class);
        $mirrors->method('getByPersona')->with('lonely_person')->willReturn([
            $this->makeMirror('PHISHING', 'Phishing'),
            $this->makeMirror('ROMANCE', 'Romance scam'),
        ]);

        $builder = new CognitiveMirrorNoteBuilder($mirrors);
        $note = $builder->build('threat-actor--abc-123', 'lonely_person', 'ROMANCE');

        self::assertIsArray($note);
        self::assertSame('note', $note['type']);
        self::assertSame('2.1', $note['spec_version']);
        self::assertStringStartsWith('note--', $note['id']);
        self::assertSame(['threat-actor--abc-123'], $note['object_refs']);
        self::assertSame(['scambuster-cognitive-mirror'], $note['labels']);
        self::assertStringContainsString('lonely_person', $note['abstract']);
        self::assertStringContainsString('Romance scam', $note['abstract']);
        self::assertStringContainsString('Hunted victim profile:', $note['content']);
        self::assertStringContainsString('Cognitive lever exploited:', $note['content']);
        self::assertStringContainsString('Mirror analysis:', $note['content']);

        $mirror = $note['x_scambuster_mirror'];
        self::assertIsArray($mirror);
        self::assertSame('1.0', $mirror['schema_version']);
        self::assertSame('lonely_person', $mirror['persona_code']);
        self::assertSame('ROMANCE', $mirror['scam_type_code']);
        self::assertSame('Lonely retiree, low tech literacy', $mirror['hunted_victim_profile']);
        self::assertSame('Trust building, then financial pressure', $mirror['cognitive_lever']);
        self::assertSame('gpt-4o-mini', $mirror['generated_by_model']);
        self::assertSame('v1', $mirror['prompt_version']);
    }

    public function testNoteIdIsDeterministicAcrossInvocations(): void
    {
        $mirrors = $this->createMock(PersonaMirrorReaderInterface::class);
        $mirrors->method('getByPersona')->willReturn([$this->makeMirror('ROMANCE')]);

        $builder = new CognitiveMirrorNoteBuilder($mirrors);
        $note1 = $builder->build('threat-actor--abc', 'lonely_person', 'ROMANCE');
        $note2 = $builder->build('threat-actor--abc', 'lonely_person', 'ROMANCE');

        self::assertSame($note1['id'], $note2['id']);
    }

    public function testNoteIdIsCaseInsensitiveOnScamTypeCode(): void
    {
        $mirrors = $this->createMock(PersonaMirrorReaderInterface::class);
        $mirrors->method('getByPersona')->willReturn([$this->makeMirror('ROMANCE')]);

        $builder = new CognitiveMirrorNoteBuilder($mirrors);
        $noteUpper = $builder->build('threat-actor--abc', 'lonely_person', 'ROMANCE');
        $noteLower = $builder->build('threat-actor--abc', 'lonely_person', 'romance');

        self::assertSame($noteUpper['id'], $noteLower['id'], 'Note id must be stable regardless of input case.');
    }

    public function testScamTypeMatchIsCaseInsensitive(): void
    {
        $mirrors = $this->createMock(PersonaMirrorReaderInterface::class);
        $mirrors->method('getByPersona')->willReturn([$this->makeMirror('ROMANCE')]);

        $builder = new CognitiveMirrorNoteBuilder($mirrors);
        $note = $builder->build('threat-actor--abc', 'lonely_person', 'romance');

        self::assertIsArray($note, 'Lookup must be case-insensitive on scam_type_code.');
    }

    /**
     * @return array{
     *   scam_type_code: string,
     *   scam_type_label: string,
     *   hunted_victim_profile: string,
     *   cognitive_lever: string,
     *   mirror_explanation: string,
     *   generated_at: string,
     *   generated_by_model: string,
     *   prompt_version: string,
     * }
     */
    private function makeMirror(string $scamTypeCode, string $label = ''): array
    {
        return [
            'scam_type_code' => $scamTypeCode,
            'scam_type_label' => $label === '' ? $scamTypeCode : $label,
            'hunted_victim_profile' => 'Lonely retiree, low tech literacy',
            'cognitive_lever' => 'Trust building, then financial pressure',
            'mirror_explanation' => 'Scammer cultivates emotional dependence over weeks before requesting transfer.',
            'generated_at' => '2026-06-15 12:00:00',
            'generated_by_model' => 'gpt-4o-mini',
            'prompt_version' => 'v1',
        ];
    }
}

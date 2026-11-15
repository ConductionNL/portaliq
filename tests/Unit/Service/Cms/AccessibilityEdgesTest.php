<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use DateTimeImmutable;
use OCA\Portaliq\Service\Cms\AccessibilityMeasurements;
use OCA\Portaliq\Service\Cms\AccessibilityRuleSentences;
use OCA\Portaliq\Service\Cms\AccessibilityRun;
use OCA\Portaliq\Service\Cms\AccessibilityStatement;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Tests\Unit\Service\Identity\PortalIdentityStoreTrait;
use PHPUnit\Framework\TestCase;

/**
 * site-accessibility-statement: the measurement and the statement ignore
 * what is malformed instead of recording or showing it.
 *
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-status-never-goes-beyond-the-evidence-req-sas-003
 */
class AccessibilityEdgesTest extends TestCase {
	use PortalIdentityStoreTrait;

	private const NOW = '2026-10-09T12:00:00+00:00';

	protected function setUp(): void {
		$this->rows = [];
	}//end setUp()

	public function testARunKeepsOnlyWhatNamesAPageAndARule(): void {
		$run = new AccessibilityRun();

		$this->assertSame(['error' => 'axe_version_missing'], $run->normalise(axeVersion: '  ', tags: [], theme: '', pages: [['url' => '/', 'measured' => true]]));
		$this->assertSame(['error' => 'pages_invalid'], $run->normalise(axeVersion: '4.10.3', tags: [], theme: '', pages: ['not a page', ['url' => ''], ['measured' => true]]));

		$result = $run->normalise(
			axeVersion: '4.10.3',
			tags: ['wcag2a', '', 7],
			theme: ' vng ',
			pages: [
				['url' => '/', 'measured' => true, 'violations' => [
					'not a violation',
					['rule' => 'Not A Rule', 'impact' => 'serious'],
					['rule' => 'image-alt', 'impact' => 'unheard-of'],
					['rule' => 'image-alt', 'impact' => 'critical', 'nodes' => -4],
				]],
			]
		);

		$this->assertSame(['wcag2a'], $result['tags']);
		$this->assertSame('vng', $result['theme']);
		$this->assertSame([['rule' => 'image-alt', 'impact' => 'critical', 'nodes' => 0]], $result['pages'][0]['violations']);
	}//end testARunKeepsOnlyWhatNamesAPageAndARule()

	public function testASentenceFallsBackToTheRuleIdWhenAxeSaysNothing(): void {
		$sentences = new AccessibilityRuleSentences();

		$this->assertSame('brand-new-rule', $sentences->sentence(rule: 'brand-new-rule', locale: 'en', fallback: '  '));
		$this->assertSame('Something new', $sentences->sentence(rule: 'brand-new-rule', locale: 'nl', fallback: ' Something new '));
		$this->assertTrue($sentences->isMapped(rule: 'color-contrast'));
		$this->assertFalse($sentences->isMapped(rule: 'brand-new-rule'));
	}//end testASentenceFallsBackToTheRuleIdWhenAxeSaysNothing()

	public function testTheStatementSkipsMalformedViolationsKeepsTheWorstImpactAndReadsTextOnly(): void {
		$page = static fn (string $url, array $violations): array => ['url' => $url, 'measured' => true, 'violations' => $violations];
		$measurement = [
			'measuredAt' => '2026-10-01T09:00:00+00:00',
			'axeVersion' => '4.10.3',
			'tags' => AccessibilityStatement::TAGS,
			'theme' => 'vng',
			'pages' => [
				$page('/', ['junk', ['rule' => '', 'impact' => 'serious'], ['rule' => 'x', 'impact' => 'nonsense'], ['rule' => 'label', 'impact' => 'minor']]),
				$page('/zoeken', [['rule' => 'label', 'impact' => 'serious']]),
			],
		];
		$portal = ['slug' => 'open-tilburg', 'title' => 'Open Tilburg', 'help' => ['email' => 12345, 'phone' => ['14 013']]];

		$statement = (new AccessibilityStatement())->build(portal: $portal, measurement: $measurement, locale: 'en', now: new DateTimeImmutable(self::NOW));

		$this->assertCount(1, $statement['issues']);
		$this->assertSame('label', $statement['issues'][0]['rule']);
		$this->assertSame('serious', $statement['issues'][0]['impact']);
		$this->assertSame(2, $statement['issues'][0]['pages']);
		$this->assertSame(['email' => '', 'phone' => ''], $statement['contact']);
	}//end testTheStatementSkipsMalformedViolationsKeepsTheWorstImpactAndReadsTextOnly()

	public function testAnImpossibleAuditDateSupportsNothing(): void {
		$audit = ['party' => 'Stichting', 'date' => '2026-99-99', 'reportUrl' => 'https://example.nl/r.pdf', 'result' => 'B'];

		$statement = new AccessibilityStatement();
		$this->assertNotSame(AccessibilityStatement::AUDIT_OK, $statement->verdict(audit: $audit, now: new DateTimeImmutable(self::NOW)));
	}//end testAnImpossibleAuditDateSupportsNothing()

	public function testALooseReaderCannotHandOverAnotherPortalsRun(): void {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturn([
			'junk',
			['portal' => 'elders', 'measuredAt' => '2026-10-09T09:00:00+00:00'],
			['portal' => 'open-tilburg', 'measuredAt' => '2026-10-02T09:00:00+00:00'],
			['portal' => 'open-tilburg', 'measuredAt' => '2026-10-01T09:00:00+00:00'],
		]);

		$latest = (new AccessibilityMeasurements($reader, $this->fakeWriter()))->latest(slug: 'open-tilburg');

		$this->assertSame('2026-10-02T09:00:00+00:00', $latest['measuredAt'] ?? null);
	}//end testALooseReaderCannotHandOverAnotherPortalsRun()

	public function testTheMeasurementsIgnoreWhatIsNotThePortalsAndNotARoute(): void {
		$this->seedRow('portal', ['title' => 'Open Tilburg', 'slug' => 'open-tilburg', 'status' => 'published']);
		$this->seedRow('accessibilityMeasurement', ['portal' => 'open-tilburg', 'measuredAt' => '2026-10-01T09:00:00+00:00']);
		$this->seedRow('accessibilityMeasurement', ['portal' => 'elders', 'measuredAt' => '2026-10-05T09:00:00+00:00']);

		$measurements = new AccessibilityMeasurements($this->fakeReader(), $this->fakeWriter());

		$this->assertNull($measurements->portalBySlug(slug: ''));
		$this->assertNull($measurements->latest(slug: ''));
		$this->assertSame('2026-10-01T09:00:00+00:00', $measurements->latest(slug: 'open-tilburg')['measuredAt'] ?? null);

		$portal = $measurements->portalBySlug(slug: 'open-tilburg');
		$saved  = $measurements->saveSettings(
			portal: $portal,
			audit: ['party' => ['not', 'a string'], 'date' => '', 'result' => 'Z', 'reportUrl' => ' https://example.nl/r.pdf '],
			registerUrl: '',
			pages: [42, '//elders.nl', '/contact'],
			now: new DateTimeImmutable(self::NOW)
		);

		$this->assertSame(['reportUrl' => 'https://example.nl/r.pdf'], $saved['accessibilityAudit']);
		$this->assertSame(['/contact'], $saved['accessibilityPages']);
		$this->assertSame(['error' => 'register_url_invalid'], $measurements->saveSettings(portal: $portal, audit: [], registerUrl: 'http://x.nl', pages: [], now: new DateTimeImmutable(self::NOW)));
		$this->assertSame(['error' => 'save_failed'], $measurements->saveSettings(portal: ['slug' => 'open-tilburg', 'id' => 'nope'], audit: [], registerUrl: '', pages: [], now: new DateTimeImmutable(self::NOW)));
	}//end testTheMeasurementsIgnoreWhatIsNotThePortalsAndNotARoute()
}//end class

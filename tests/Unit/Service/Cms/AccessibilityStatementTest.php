<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use DateTimeImmutable;
use OCA\Portaliq\Service\Cms\AccessibilityStatement;
use PHPUnit\Framework\TestCase;

/**
 * site-accessibility-statement REQ-SAS-002 and REQ-SAS-003: the statement
 * follows the latest measurement, and its status never goes beyond the
 * evidence.
 *
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-each-portal-publishes-a-statement-in-the-national-model-req-sas-002
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-status-never-goes-beyond-the-evidence-req-sas-003
 */
class AccessibilityStatementTest extends TestCase {

	private const NOW = '2026-10-09T12:00:00+00:00';

	public function testKnownIssuesFollowTheMeasurement(): void {
		$statement = $this->build(measurement: $this->contrastOnTwoPages(), locale: 'en');

		$this->assertCount(1, $statement['issues']);
		$issue = $statement['issues'][0];
		$this->assertSame('color-contrast', $issue['rule']);
		$this->assertStringContainsString('contrast', strtolower($issue['sentence']));
		$this->assertSame('serious', $issue['impact']);
		$this->assertSame(2, $issue['pages']);
		$this->assertSame('2026-10-01T09:00:00+00:00', $statement['measurement']['measuredAt']);
		$this->assertSame(4, $statement['measurement']['pagesMeasured']);

		$dutch = $this->build(measurement: $this->contrastOnTwoPages(), locale: 'nl');
		$this->assertStringContainsString('contrast', strtolower($dutch['issues'][0]['sentence']));
		$this->assertNotSame($issue['sentence'], $dutch['issues'][0]['sentence']);
	}//end testKnownIssuesFollowTheMeasurement()

	public function testANewerMeasurementReplacesTheIssues(): void {
		$newer = [
			'measuredAt' => '2026-10-08T09:00:00+00:00',
			'axeVersion' => '4.10.3',
			'tags' => AccessibilityStatement::TAGS,
			'theme' => 'vng',
			'pages' => [
				['url' => '/', 'measured' => true, 'violations' => []],
				['url' => '/zoeken', 'measured' => true, 'violations' => []],
			],
		];

		$statement = $this->build(measurement: $newer);

		$this->assertSame([], $statement['issues']);
		$this->assertSame('2026-10-08T09:00:00+00:00', $statement['measurement']['measuredAt']);
	}//end testANewerMeasurementReplacesTheIssues()

	public function testNoAuditMeansAtMostC(): void {
		$clean = $this->contrastOnTwoPages();
		foreach ($clean['pages'] as $index => $page) {
			$clean['pages'][$index]['violations'] = [];
		}

		$statement = $this->build(measurement: $clean);

		$this->assertSame('C', $statement['status']);
		$this->assertTrue($statement['automatedOnly']);
		$this->assertNull($statement['audit']);

		// A claim written straight into the portal record without the audit
		// fields still does not lift the status.
		$claimed = $this->build(measurement: $clean, audit: ['result' => 'A']);
		$this->assertSame('C', $claimed['status']);
		$this->assertTrue($claimed['automatedOnly']);

		// Nothing measured and no audit: no status is claimed at all.
		$this->assertNull($this->build(measurement: null)['status']);
	}//end testNoAuditMeansAtMostC()

	public function testAnAuditSupportsB(): void {
		$statement = $this->build(
			measurement: $this->contrastOnTwoPages(),
			audit: ['party' => 'Stichting Toegankelijk', 'date' => '2026-06-01', 'reportUrl' => 'https://example.nl/rapport.pdf', 'result' => 'B']
		);

		$this->assertSame('B', $statement['status']);
		$this->assertFalse($statement['automatedOnly']);
		$this->assertSame('Stichting Toegankelijk', $statement['audit']['party']);
		$this->assertSame('2026-06-01', $statement['audit']['date']);
		$this->assertSame('https://example.nl/rapport.pdf', $statement['audit']['reportUrl']);
		$this->assertFalse($statement['auditExpired']);
	}//end testAnAuditSupportsB()

	public function testAnAuditOlderThanThreeYearsSupportsNothing(): void {
		$statement = $this->build(
			measurement: $this->contrastOnTwoPages(),
			audit: ['party' => 'Stichting Toegankelijk', 'date' => '2023-10-01', 'reportUrl' => 'https://example.nl/rapport.pdf', 'result' => 'A']
		);

		$this->assertSame('C', $statement['status']);
		$this->assertTrue($statement['automatedOnly']);
		$this->assertNull($statement['audit']);
		$this->assertTrue($statement['auditExpired']);
	}//end testAnAuditOlderThanThreeYearsSupportsNothing()

	public function testAnUnmeasuredPageIsListedAndAnUnknownRuleFallsBackToAxe(): void {
		$measurement = $this->contrastOnTwoPages();
		$measurement['pages'][] = ['url' => '/extern', 'measured' => false, 'reason' => 'The page refused to load in a frame.'];
		$measurement['pages'][0]['violations'][] = ['rule' => 'brand-new-rule', 'impact' => 'minor', 'nodes' => 1, 'help' => 'Something new', 'helpUrl' => 'https://dequeuniversity.com/rules/axe/4.10/brand-new-rule'];

		$statement = $this->build(measurement: $measurement, registerUrl: 'https://www.toegankelijkheidsverklaring.nl/register/1');

		$this->assertSame([['url' => '/extern', 'reason' => 'The page refused to load in a frame.']], $statement['notMeasured']);
		$this->assertSame(4, $statement['measurement']['pagesMeasured']);
		$this->assertSame(1, $statement['measurement']['pagesNotMeasured']);
		$byRule = array_column($statement['issues'], null, 'rule');
		$this->assertSame('Something new', $byRule['brand-new-rule']['sentence']);
		$this->assertSame('https://dequeuniversity.com/rules/axe/4.10/brand-new-rule', $byRule['brand-new-rule']['helpUrl']);
		// The serious issue comes before the minor one.
		$this->assertSame('color-contrast', $statement['issues'][0]['rule']);
		$this->assertSame('https://www.toegankelijkheidsverklaring.nl/register/1', $statement['registerUrl']);
		$this->assertSame('Open Tilburg', $statement['website']);
		$this->assertSame(['email' => 'toegankelijkheid@tilburg.nl', 'phone' => '14 013'], $statement['contact']);
	}//end testAnUnmeasuredPageIsListedAndAnUnknownRuleFallsBackToAxe()

	public function testTheDemoMeasurementsFitTheSchemaAndBuildAStatement(): void {
		$root     = __DIR__.'/../../../../lib/Settings/';
		$mock     = json_decode((string)file_get_contents($root.'portaliq_mock_register.json'), true);
		$register = json_decode((string)file_get_contents($root.'portaliq_register.json'), true);
		$fragment = $register['components']['schemas']['accessibilityMeasurement'];
		$schema   = json_decode((string)json_encode(['type' => 'object', 'required' => $fragment['required'], 'properties' => $fragment['properties']]), false);

		$rows = array_values(array_filter($mock['components']['objects'], static fn (array $o): bool => ($o['@self']['schema'] ?? '') === 'accessibilityMeasurement'));
		$this->assertGreaterThanOrEqual(3, count($rows));
		foreach ($rows as $row) {
			unset($row['@self']);
			$this->assertTrue((new \Opis\JsonSchema\Validator())->validate(json_decode((string)json_encode($row), false), $schema)->isValid());
			$statement = $this->build(measurement: $row);
			$this->assertSame('C', $statement['status']);
		}
	}//end testTheDemoMeasurementsFitTheSchemaAndBuildAStatement()

	/**
	 * @param array<string, mixed>|null $measurement The latest measurement.
	 * @param array<string, mixed>|null $audit       The portal's audit.
	 * @param string                    $locale      The statement's language.
	 * @param string                    $registerUrl The register entry.
	 *
	 * @return array<string, mixed>
	 */
	private function build(?array $measurement, ?array $audit = null, string $locale = 'en', string $registerUrl = ''): array {
		$portal = ['slug' => 'open-tilburg', 'title' => 'Open Tilburg', 'organisation' => 'tilburg', 'theme' => 'vng', 'help' => ['email' => 'toegankelijkheid@tilburg.nl', 'phone' => '14 013']];
		if ($audit !== null) {
			$portal['accessibilityAudit'] = $audit;
		}

		if ($registerUrl !== '') {
			$portal['accessibilityRegisterUrl'] = $registerUrl;
		}

		return (new AccessibilityStatement())->build(
			portal: $portal,
			measurement: $measurement,
			locale: $locale,
			now: new DateTimeImmutable(self::NOW)
		);
	}//end build()

	/**
	 * @return array<string, mixed>
	 */
	private function contrastOnTwoPages(): array {
		$contrast = ['rule' => 'color-contrast', 'impact' => 'serious', 'nodes' => 3, 'help' => 'Elements must meet minimum color contrast ratio thresholds', 'helpUrl' => 'https://dequeuniversity.com/rules/axe/4.10/color-contrast'];

		return [
			'measuredAt' => '2026-10-01T09:00:00+00:00',
			'axeVersion' => '4.10.3',
			'tags' => AccessibilityStatement::TAGS,
			'theme' => 'vng',
			'pages' => [
				['url' => '/', 'measured' => true, 'violations' => [$contrast]],
				['url' => '/zoeken', 'measured' => true, 'violations' => [$contrast]],
				['url' => '/publicatie/1', 'measured' => true, 'violations' => []],
				['url' => '/niet-gevonden', 'measured' => true, 'violations' => []],
			],
		];
	}//end contrastOnTwoPages()
}//end class

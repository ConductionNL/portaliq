<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Service\CaseResultLink;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * woo-dossier-in-my-cases REQ-WDM-003: a case's result link reaches the
 * resident only when it is https on one of the instance's trusted domains;
 * any other is dropped with one warning.
 *
 * @covers \OCA\Portaliq\Service\CaseResultLink
 *
 * @spec openspec/changes/woo-dossier-in-my-cases/specs/citizen-writes-on-their-own-case/spec.md#requirement-the-case-links-to-where-its-result-is-public-req-wdm-003
 */
class CitizenCaseResultLinkTest extends TestCase {

	/**
	 * The filter over these trusted domains.
	 *
	 * @param array<int, string>   $trusted The trusted domains.
	 * @param LoggerInterface|null $logger  The logger.
	 *
	 * @return CaseResultLink
	 */
	private function links(array $trusted, ?LoggerInterface $logger = null): CaseResultLink {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->with('trusted_domains', [])->willReturn($trusted);

		return new CaseResultLink(config: $config, logger: ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end links()

	public function testATrustedHttpsLinkIsKeptWithItsLabelTrimmed(): void {
		$case = $this->links(['zuiddrecht.nl'])->kept(['id' => 'c-1', 'resultLink' => ['label' => '  Bekijk wat openbaar is gemaakt ', 'url' => ' https://Zuiddrecht.nl/woo/2026-0087 ', 'extra' => 'x']]);

		$this->assertSame(['label' => 'Bekijk wat openbaar is gemaakt', 'url' => 'https://Zuiddrecht.nl/woo/2026-0087'], $case['resultLink']);
	}//end testATrustedHttpsLinkIsKeptWithItsLabelTrimmed()

	public function testALongLabelIsCutAndAMissingOneStaysEmpty(): void {
		$links = $this->links(['zuiddrecht.nl']);

		$this->assertSame(80, mb_strlen($links->kept(['resultLink' => ['label' => str_repeat('é', 90), 'url' => 'https://zuiddrecht.nl/x']])['resultLink']['label']));
		$this->assertSame('', $links->kept(['resultLink' => ['url' => 'https://zuiddrecht.nl/x']])['resultLink']['label']);
	}//end testALongLabelIsCutAndAMissingOneStaysEmpty()

	/**
	 * Links that are not shown.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function droppedLinks(): array {
		return [
			'http' => [['url' => 'http://zuiddrecht.nl/x']],
			'foreign host' => [['url' => 'http://example.com/x']],
			'foreign https host' => [['url' => 'https://example.com/x']],
			'lookalike host' => [['url' => 'https://zuiddrecht.nl.example.com/x']],
			'credentials' => [['url' => 'https://user:pw@zuiddrecht.nl/x']],
			'other port' => [['url' => 'https://zuiddrecht.nl:8443/x']],
			'javascript' => [['url' => 'javascript:alert(1)']],
			'no url' => [['label' => 'x']],
			'a string' => ['https://zuiddrecht.nl/x'],
		];
	}//end droppedLinks()

	/**
	 * A link that is not https on a trusted domain is dropped and warned about once.
	 *
	 * @param mixed $link The link.
	 *
	 * @return void
	 */
	#[DataProvider('droppedLinks')]
	public function testAnyOtherLinkIsDroppedWithOneWarning(mixed $link): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$case = $this->links(['zuiddrecht.nl'], $logger)->kept(['id' => 'c-1', 'status' => 'open', 'resultLink' => $link]);

		$this->assertSame(['id' => 'c-1', 'status' => 'open'], $case);
	}//end testAnyOtherLinkIsDroppedWithOneWarning()

	public function testAPortAndAWildcardInTheTrustedDomainsAreHonoured(): void {
		$links = $this->links(['localhost:8080', '*.gemeente.nl']);

		$this->assertArrayHasKey('resultLink', $links->kept(['resultLink' => ['url' => 'https://localhost:8080/x']]));
		$this->assertArrayNotHasKey('resultLink', $links->kept(['resultLink' => ['url' => 'https://localhost/x']]));
		$this->assertArrayHasKey('resultLink', $links->kept(['resultLink' => ['url' => 'https://woo.gemeente.nl/x']]));
		$this->assertArrayNotHasKey('resultLink', $links->kept(['resultLink' => ['url' => 'https://gemeente.nl/x']]));
		$this->assertArrayNotHasKey('resultLink', $links->kept(['resultLink' => ['url' => 'https://evilgemeente.nl/x']]));
	}//end testAPortAndAWildcardInTheTrustedDomainsAreHonoured()

	public function testACaseWithoutALinkPassesUnchangedAndUnwarned(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$links = $this->links(['zuiddrecht.nl'], $logger);

		$this->assertSame(['id' => 'c-1'], $links->kept(['id' => 'c-1']));
		$this->assertSame(['id' => 'c-1'], $links->kept(['id' => 'c-1', 'resultLink' => null]));
		$this->assertSame(['id' => 'c-1'], $links->kept(['id' => 'c-1', 'resultLink' => '']));
	}//end testACaseWithoutALinkPassesUnchangedAndUnwarned()
}//end class

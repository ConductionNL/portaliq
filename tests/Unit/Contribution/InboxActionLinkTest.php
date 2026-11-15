<?php

/**
 * InboxActionLink: the link a message may offer, kept only when it stays on
 * the portal.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Contribution
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\InboxActionLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The same-site message link.
 */
class InboxActionLinkTest extends TestCase {
	/**
	 * Hrefs and whether a message may link to them.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function hrefs(): array {
		return [
			'a portal path'      => ['/mijn/zaken/Z-1', true],
			'a path with query'  => ['/mijn/zaken?tab=documenten', true],
			'the root'           => ['/', true],
			'a fragment'         => ['#zaak/Z-1', true],
			'a fragment, query'  => ['#tab=a.b-c_d', true],
			'protocol relative'  => ['//evil.example', false],
			'an absolute url'    => ['https://example.org/', false],
			'javascript'         => ['javascript:alert(1)', false],
			'a backslash'        => ['/\\evil.example', false],
			'a control char'     => ["/mijn\tzaken", false],
			'a newline'          => ["/mijn\n", false],
			'a bare fragment'    => ['#', false],
			'a spaced fragment'  => ['#zaak 1', false],
			'relative'           => ['mijn/zaken', false],
		];
	}//end hrefs()

	/**
	 * Only a path on the portal or an in-page fragment is kept.
	 *
	 * @param string $href The declared href.
	 * @param bool $kept Whether the link survives.
	 *
	 * @return void
	 */
	#[DataProvider('hrefs')]
	public function testOnlySameSiteHrefsAreKept(string $href, bool $kept): void {
		$link = (new InboxActionLink())->sameSite(action: ['label' => 'Bekijk', 'href' => $href]);
		if ($kept === true) {
			$this->assertSame(expected: ['label' => 'Bekijk', 'href' => $href], actual: $link);
			return;
		}

		$this->assertNull(actual: $link);
	}//end testOnlySameSiteHrefsAreKept()

	/**
	 * The label is trimmed and must say something; other keys are dropped.
	 *
	 * @return void
	 */
	public function testTheLabelMustSaySomething(): void {
		$links = new InboxActionLink();
		$this->assertSame(
			expected: ['label' => 'Bekijk zaak', 'href' => '/z'],
			actual: $links->sameSite(action: ['label' => '  Bekijk zaak ', 'href' => '/z', 'target' => '_blank'])
		);
		$this->assertNull(actual: $links->sameSite(action: ['label' => '  ', 'href' => '/z']));
		$this->assertNull(actual: $links->sameSite(action: ['label' => 5, 'href' => '/z']));
		$this->assertNull(actual: $links->sameSite(action: ['href' => '/z']));
		$this->assertNull(actual: $links->sameSite(action: ['label' => 'Bekijk', 'href' => ['/z']]));
		$this->assertNull(actual: $links->sameSite(action: '/z'));
	}//end testTheLabelMustSaySomething()
}//end class

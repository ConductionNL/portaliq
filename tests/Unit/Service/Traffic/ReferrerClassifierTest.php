<?php

/**
 * Unit tests for ReferrerClassifier.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Traffic;

use OCA\Portaliq\Service\Traffic\ReferrerClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Where a visit came from, in GA4's vocabulary.
 */
class ReferrerClassifierTest extends TestCase {

	/**
	 * The classifier under test.
	 *
	 * @var ReferrerClassifier
	 */
	private ReferrerClassifier $classifier;


	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->classifier = new ReferrerClassifier();
	}//end setUp()


	/**
	 * Referrer hosts map onto channels, and the page's own host is internal.
	 *
	 * @return void
	 */
	public function testChannelsFollowTheReferrerHost(): void {
		$cases = [
			['', 'direct'],
			['www.google.nl', 'organic search'],
			['duckduckgo.com', 'organic search'],
			['www.facebook.com', 'social'],
			['t.co', 'social'],
			['nos.nl', 'referral'],
			['open-tilburg.nl', 'internal'],
		];

		foreach ($cases as [$host, $channel]) {
			$this->assertSame(
				$channel,
				$this->classifier->channel(referrerHost: $host, pageHost: 'open-tilburg.nl', campaign: []),
				'host ' . $host
			);
		}
	}//end testChannelsFollowTheReferrerHost()


	/**
	 * Campaign parameters beat the referrer, and a mail medium is email.
	 *
	 * @return void
	 */
	public function testCampaignParametersWinOverTheReferrer(): void {
		$this->assertSame(
			'email',
			$this->classifier->channel(referrerHost: 'www.google.nl', pageHost: 'x', campaign: ['medium' => 'email'])
		);
		$this->assertSame(
			'campaign',
			$this->classifier->channel(referrerHost: '', pageHost: 'x', campaign: ['campaign' => 'woo-week'])
		);
	}//end testCampaignParametersWinOverTheReferrer()


	/**
	 * utm_ and mtm_ spellings both parse, and the GA spelling wins when both
	 * are present.
	 *
	 * @return void
	 */
	public function testCampaignParametersAreParsedFromTheLocation(): void {
		$campaign = $this->classifier->campaign(
			location: 'https://open-tilburg.nl/woo?utm_source=nieuwsbrief&utm_medium=email&utm_campaign=woo-week&mtm_kwd=verzoek&q=geheim'
		);

		$this->assertSame(
			['campaign' => 'woo-week', 'source' => 'nieuwsbrief', 'medium' => 'email', 'term' => 'verzoek'],
			$campaign
		);
		$this->assertSame([], $this->classifier->campaign(location: 'https://open-tilburg.nl/woo'));
	}//end testCampaignParametersAreParsedFromTheLocation()


	/**
	 * The page path drops the WHOLE query string, not only the campaign keys.
	 *
	 * The page-level aggregate must not become a second place search terms
	 * and case numbers are stored.
	 *
	 * @return void
	 */
	public function testThePathDropsTheWholeQueryString(): void {
		$this->assertSame('/woo', $this->classifier->path(location: 'https://open-tilburg.nl/woo?q=achternaam&utm_source=x'));
		$this->assertSame('/', $this->classifier->path(location: 'https://open-tilburg.nl'));
		// A mail marker is not a URL with a path; it is kept as it is.
		$this->assertSame('mailto:blast/42', $this->classifier->path(location: 'mailto:blast/42'));
	}//end testThePathDropsTheWholeQueryString()


	/**
	 * The host is lower-cased and empty for garbage.
	 *
	 * @return void
	 */
	public function testTheHostIsNormalised(): void {
		$this->assertSame('www.google.nl', $this->classifier->host(referrer: 'https://WWW.Google.NL/search?q=x'));
		$this->assertSame('', $this->classifier->host(referrer: 'not a url'));
		$this->assertSame('', $this->classifier->host(referrer: ''));
	}//end testTheHostIsNormalised()


}//end class

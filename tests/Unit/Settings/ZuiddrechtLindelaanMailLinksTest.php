<?php
/**
 * Example data for DqZaakEmail: the three mails linked to the Lindelaan Woo
 * case carry the sender's name and the details their rows show.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The Lindelaan case's linked mail.
 */
class ZuiddrechtLindelaanMailLinksTest extends TestCase {

	/**
	 * The set's email links by key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function links(): array {
		$set = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$this->assertIsArray(actual: $set);

		return array_column(($set['emailLinks'] ?? []), 'data', 'key');
	}//end links()

	/**
	 * Each mail is on the Lindelaan case, by its Message-ID, with the sender's
	 * name and the details DqZaakEmail draws.
	 *
	 * @return void
	 */
	public function testEachMailCarriesItsSenderNameAndDetails(): void {
		$links = $this->links();
		$this->assertSame(
			expected: ['lindelaan-mail-aanvulling', 'lindelaan-mail-vraag-mobiliteit', 'lindelaan-mail-planning-aannemer'],
			actual: array_keys($links)
		);

		$shown = [];
		foreach ($links as $key => $link) {
			$this->assertSame(expected: '{{object:woo-fietspad.id}}', actual: $link['object']);
			$this->assertStringStartsWith(prefix: '<', string: $link['messageId']);
			$shown[$key] = [$link['senderName'], $link['direction'], $link['attachmentCount'], $link['linkedVia'], ($link['archivedAs'] ?? null)];
		}

		$this->assertSame(
			expected: [
				'lindelaan-mail-aanvulling'        => ['{{cast:sanne.displayName}}', 'inbound', 1, 'mailbox', 'pdf'],
				'lindelaan-mail-vraag-mobiliteit'  => ['{{cast:pieter.displayName}}', 'outbound', 0, 'manual', 'pdf'],
				'lindelaan-mail-planning-aannemer' => ['Aannemersbedrijf Van Wijk', 'inbound', 2, 'file', null],
			],
			actual: $shown
		);
		$this->assertSame(expected: '{{cast:lars.displayName}}', actual: $links['lindelaan-mail-vraag-mobiliteit']['recipientName']);
	}//end testEachMailCarriesItsSenderNameAndDetails()

	/**
	 * Only the mail linked by the mailbox names it, as the row reads it:
	 * "automatisch gekoppeld via het Woo-postvak".
	 *
	 * @return void
	 */
	public function testTheMailboxLinkNamesTheWooMailbox(): void {
		$named = [];
		foreach ($this->links() as $key => $link) {
			if (array_key_exists('mailboxName', $link) === true) {
				$named[$key] = $link['mailboxName'];
			}
		}

		$this->assertSame(expected: ['lindelaan-mail-aanvulling' => 'het Woo-postvak'], actual: $named);
	}//end testTheMailboxLinkNamesTheWooMailbox()

	/**
	 * The two mails DqZaakEmail draws with a time are from this week (the row
	 * shows the time only for a mail of the last seven days), and Sanne's
	 * addition is the same moment as the contact moment that logs it.
	 *
	 * @return void
	 */
	public function testTheCaseMailsAreFromThisWeek(): void {
		$dates = array_map(static fn (array $link): string => $link['mailDate'], $this->links());
		$this->assertSame(
			expected: [
				'lindelaan-mail-aanvulling'        => '{{datetime:-2 09:15}}',
				'lindelaan-mail-vraag-mobiliteit'  => '{{datetime:-3 11:02}}',
				'lindelaan-mail-planning-aannemer' => '2026-03-12T10:40:00+01:00',
			],
			actual: $dates
		);

		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$logged  = array_filter(
			($set['objects'] ?? []),
			static fn (array $object): bool => ($object['key'] ?? '') === 'woo-fietspad-aanvulling'
		);
		$moments = array_column($logged, 'data');
		$this->assertCount(expectedCount: 1, haystack: $moments);
		$this->assertSame(expected: $dates['lindelaan-mail-aanvulling'], actual: $moments[0]['startTime']);
		$this->assertSame(expected: '{{datetime:-2 09:17}}', actual: $moments[0]['endTime']);
	}//end testTheCaseMailsAreFromThisWeek()
}//end class

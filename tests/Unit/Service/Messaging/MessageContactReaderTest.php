<?php

/**
 * Who a resident may write to is the app's answer for a row the resident
 * owns, read through the scoped reader, and a new conversation is proven the
 * same way at the moment it is started (site-messages-per-record).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-a-resident-may-start-a-conversation-only-with-a-contact-of-their-own-record
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Messaging;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCA\Portaliq\Controller\MessageGuardianController;
use OCA\Portaliq\Service\Messaging\GuardianMessagingLeafInterface;
use OCA\Portaliq\Service\Messaging\MessageContactReader;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-a-resident-may-start-a-conversation-only-with-a-contact-of-their-own-record
 */
class MessageContactReaderTest extends TestCase {

	private const SUBJECT = ['subjectRef' => 'fatima', 'audience' => 'parent', 'organisation' => '', 'trust' => 'substantial'];

	/**
	 * The school's provider: Meester Daan for Vera's group, Juf Esra for Sami's.
	 *
	 * @return object
	 */
	private function provider(): object {
		return new class {
			/** @var array<int, string> The ids it was asked about. */
			public array $asked = [];

			/**
			 * @param string $id An enrolment id.
			 *
			 * @return array<int, mixed>
			 */
			public function messageContactsFor(string $id): array {
				$this->asked[] = $id;
				return match ($id) {
					'enr-vera' => [['staffRef' => 'po-leerkracht-09', 'name' => 'Meester Daan', 'role' => 'Leerkracht'], ['name' => 'no person'], 'junk'],
					'enr-sami' => [['staffRef' => 'po-leerkracht-07', 'name' => 'Juf Esra', 'role' => 'Leerkracht']],
					default    => [],
				};
			}
		};
	}

	/**
	 * A reader over the school's contribution.
	 *
	 * @param object                    $provider The app provider.
	 * @param array<int, array>         $rows     The rows the scoped reader returns.
	 * @param array<string, array>|null $byId     What a single read returns, by id.
	 * @param string                    $minTrust The collection's trust.
	 *
	 * @return MessageContactReader
	 */
	private function reader(object $provider, array $rows, ?array $byId = null, string $minTrust = 'low'): MessageContactReader {
		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn(
			[
				'contributions' => [
					[
						'app' => 'learniq',
						'collections' => [
							['id' => 'parentGrades', 'register' => 'learniq', 'schema' => 'grade-entry', 'fields' => ['value']],
							[
								'id' => 'parentEnrolments', 'register' => 'learniq', 'schema' => 'enrolment', 'scopeField' => 'learnerRef',
								'minTrust' => $minTrust, 'fields' => ['learnerName', 'cohortName'],
								'contacts' => ['provider' => 'messageContactsFor', 'recordLabelFields' => ['learnerName', 'cohortName'], 'composeLabel' => 'Een bericht aan de leerkracht'],
							],
						],
					],
				],
			]
		);

		$objects = $this->createMock(PortalObjectReader::class);
		$objects->method('readCollection')->willReturn($rows);
		$objects->method('readObject')->willReturnCallback(static fn (...$args): ?array => ($byId ?? [])[$args['id'] ?? $args[4]] ?? null);

		$locator = $this->createMock(PortalProviderLocator::class);
		$locator->method('locate')->willReturn($provider);

		return new MessageContactReader($registry, $objects, $locator, $this->createMock(LoggerInterface::class));
	}

	/**
	 * Her two children's enrolments.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function rows(): array {
		return [
			['id' => 'enr-vera', 'learnerName' => 'Vera', 'cohortName' => 'Groep 7'],
			['id' => 'enr-sami', 'learnerName' => 'Sami', 'cohortName' => 'Groep 4'],
		];
	}

	public function testEachOwnedRowAsksTheProviderAndCarriesItsRecord(): void {
		$answer = $this->reader(provider: $this->provider(), rows: $this->rows())->contactsFor(subject: self::SUBJECT);

		$this->assertSame('Een bericht aan de leerkracht', $answer['composeLabel']);
		$this->assertSame(
			[
				['staffRef' => 'po-leerkracht-09', 'name' => 'Meester Daan', 'role' => 'Leerkracht', 'recordRef' => 'enr-vera', 'recordLabel' => 'Vera, Groep 7', 'collection' => 'parentEnrolments'],
				['staffRef' => 'po-leerkracht-07', 'name' => 'Juf Esra', 'role' => 'Leerkracht', 'recordRef' => 'enr-sami', 'recordLabel' => 'Sami, Groep 4', 'collection' => 'parentEnrolments'],
			],
			$answer['contacts'],
			'an entry without a person, or not an entry at all, is dropped'
		);
	}

	public function testACollectionWhoseTrustIsNotMetNamesNobody(): void {
		$answer = $this->reader(provider: $this->provider(), rows: $this->rows(), minTrust: 'high')->contactsFor(subject: self::SUBJECT);

		$this->assertSame([], $answer['contacts']);
	}

	public function testAContactIsProvenOnlyForARecordSheOwnsAndAPersonTheAppNames(): void {
		$provider = $this->provider();
		$reader   = $this->reader(provider: $provider, rows: [], byId: ['enr-vera' => $this->rows()[0]]);

		$this->assertSame('Meester Daan', $reader->contactFor(subject: self::SUBJECT, staffRef: 'po-leerkracht-09', recordRef: 'enr-vera')['name']);
		$this->assertNull($reader->contactFor(subject: self::SUBJECT, staffRef: 'po-leerkracht-07', recordRef: 'enr-vera'), 'Juf Esra does not teach Vera');
		$this->assertNull($reader->contactFor(subject: self::SUBJECT, staffRef: 'po-leerkracht-07', recordRef: 'enr-other'), 'not her record');
		$this->assertNotContains('enr-other', $provider->asked, 'the provider is never asked about a record she does not own');
	}

	public function testANewConversationWithAnUnprovenContactIsRefused(): void {
		$messaging = $this->createMock(GuardianMessagingLeafInterface::class);
		$messaging->expects($this->never())->method('createContactThread');
		$messaging->expects($this->never())->method('createThread');

		$response = $this->controller(reader: $this->reader(provider: $this->provider(), rows: [], byId: []), messaging: $messaging)
			->createThread('po-leerkracht-09', 'enr-vera', 'Vraag', 'Mag Vera morgen eerder weg?');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testANewConversationStoresTheProvenContactAndTheFirstMessage(): void {
		$messaging = $this->createMock(GuardianMessagingLeafInterface::class);
		$messaging->expects($this->once())->method('createContactThread')
			->with(
				'fatima',
				$this->callback(static fn (array $contact): bool => $contact['staffRef'] === 'po-leerkracht-09' && $contact['recordLabel'] === 'Vera, Groep 7'),
				'Mag Vera morgen eerder weg?',
				'Mag Vera morgen eerder weg?'
			)
			->willReturn('thread-9');

		$response = $this->controller(reader: $this->reader(provider: $this->provider(), rows: [], byId: ['enr-vera' => $this->rows()[0]]), messaging: $messaging)
			->createThread('po-leerkracht-09', 'enr-vera', '', '  Mag Vera morgen eerder weg?  ');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('thread-9', $response->getData()['id']);
	}

	public function testAnEmptyOrOverlongMessageIsRefusedBeforeAnythingIsAsked(): void {
		$provider = $this->provider();
		$reader   = $this->reader(provider: $provider, rows: [], byId: ['enr-vera' => $this->rows()[0]]);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller(reader: $reader)->createThread('po-leerkracht-09', 'enr-vera', '', '   ')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller(reader: $reader)->createThread('po-leerkracht-09', 'enr-vera', '', str_repeat('a', 5001))->getStatus());
		$this->assertSame([], $provider->asked);
	}

	public function testTheThreadListSaysHowManyAreUnreadAndShowsTheNewest(): void {
		$messaging = $this->createMock(GuardianMessagingLeafInterface::class);
		$messaging->method('listThreads')->willReturn([['id' => 't1', 'title' => 'Huiswerk', 'recordLabel' => 'Vera, Groep 7']]);
		$messaging->method('listMessages')->willReturn(
			[
				['senderRef' => 'fatima', 'body' => 'Vraag', 'sentAt' => '2026-09-28T08:00:00+00:00', 'readBy' => ['fatima']],
				['senderRef' => 'po-leerkracht-09', 'body' => 'Antwoord', 'sentAt' => '2026-09-28T09:00:00+00:00', 'readBy' => []],
			]
		);

		$data = $this->controller(reader: null, messaging: $messaging)->threads()->getData();

		$this->assertSame(['unread' => 1, 'lastBody' => 'Antwoord', 'lastSentAt' => '2026-09-28T09:00:00+00:00', 'lastFromMe' => false], $data[0]['summary']);
		$this->assertSame('Huiswerk', $data[0]['title']);
	}

	/**
	 * The guardian controller with a resolved subject.
	 *
	 * @param MessageContactReader|null           $reader    The contact reader.
	 * @param GuardianMessagingLeafInterface|null $messaging The messaging leaf.
	 *
	 * @return MessageGuardianController
	 */
	private function controller(?MessageContactReader $reader, ?GuardianMessagingLeafInterface $messaging = null): MessageGuardianController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer token');
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn(self::SUBJECT);

		return new MessageGuardianController($request, $session, $messaging ?? $this->createMock(GuardianMessagingLeafInterface::class), null, null, $reader);
	}
}

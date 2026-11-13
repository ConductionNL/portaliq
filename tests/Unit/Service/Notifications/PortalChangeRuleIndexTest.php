<?php

/**
 * Unit tests for the index of change rules and message inboxes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Notifications;

use OCA\Portaliq\Contribution\NotificationRuleNormaliser;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\Notifications\PortalChangeRuleIndex;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The index lists every app's change rules and message inboxes once, and matches
 * a record's register and schema by id or by slug.
 */
#[CoversClass(PortalChangeRuleIndex::class)]
#[UsesClass(NotificationRuleNormaliser::class)]
class PortalChangeRuleIndexTest extends TestCase {

	/**
	 * The contribution registry.
	 *
	 * @var PortalContributionRegistry&MockObject
	 */
	private PortalContributionRegistry&MockObject $registry;

	/**
	 * The container holding the OpenRegister mappers.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->registry  = $this->createMock(PortalContributionRegistry::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A change rule matches its register and schema by slug or by id, and keeps its collection and messages.
	 *
	 * @return void
	 */
	public function testChangeRulesMatchBySlugOrId(): void {
		$this->contributions(['citizen'], [self::casesContribution()]);
		$this->slugMaps(registers: [5 => 'zaken-reg'], schemas: [9 => 'case']);
		$index = $this->index();

		$expected = [
			[
				'kind'           => 'change',
				'app'            => 'zaken',
				'collection'     => 'cases',
				'label'          => 'My cases',
				'register'       => 'zaken-reg',
				'schema'         => 'case',
				'scopeField'     => 'owner',
				'ruleKey'        => 'status.changed',
				'field'          => 'status',
				'titleField'     => 'title',
				'recipientField' => '',
				'recipientClaim' => '',
			],
		];
		$this->assertSame($expected, $index->changeRulesFor(register: '5', schema: '9'));
		$this->assertSame($expected, $index->changeRulesFor(register: 'zaken-reg', schema: 'case'));
		$this->assertSame([], $index->changeRulesFor(register: '6', schema: '9'));
		$this->assertSame([], $index->changeRulesFor(register: '', schema: '9'));
		$this->assertSame([], $index->inboxesFor(register: '5', schema: '9'));

		$this->assertSame(
			['collection' => self::casesContribution()['collections'][0], 'messages' => ['closed' => ['subject' => 'Closed']]],
			$index->details(entry: $expected[0])
		);
		$this->assertSame(['collection' => [], 'messages' => []], $index->details(entry: ['app' => 'other']));
	}//end testChangeRulesMatchBySlugOrId()

	/**
	 * An inbox is indexed when the app nudges on new messages or the inbox names a message box provider.
	 *
	 * @return void
	 */
	public function testInboxesAreIndexed(): void {
		$nudging = [
			'app'           => 'berichten',
			'notifications' => ['message.created'],
			'collections'   => [
				['id' => 'inbox', 'kind' => 'inbox', 'register' => 'b', 'schema' => 'msg'],
				['id' => 'list', 'kind' => 'list', 'register' => 'b', 'schema' => 'msg'],
			],
		];
		$provider = [
			'app'           => 'post',
			'notifications' => 'not a list',
			'collections'   => [
				['id' => 'letters', 'label' => 'Letters', 'kind' => 'inbox', 'register' => 'b', 'schema' => 'msg', 'scopeField' => 'to', 'messageBox' => ['recipientProvider' => 'mo', 'bodyField' => 'text', 'subjectField' => 'about']],
				['id' => 'silent', 'kind' => 'inbox', 'register' => 'b', 'schema' => 'msg'],
			],
		];
		$this->contributions(['citizen'], [$nudging, $provider]);
		$this->slugMaps(registers: [], schemas: []);

		$this->assertSame(
			[
				['kind' => 'inbox', 'app' => 'berichten', 'collection' => 'inbox', 'label' => 'inbox', 'register' => 'b', 'schema' => 'msg', 'scopeField' => 'subjectRef', 'nudge' => '1', 'recipientProvider' => '', 'bodyField' => '', 'subjectField' => ''],
				['kind' => 'inbox', 'app' => 'post', 'collection' => 'letters', 'label' => 'Letters', 'register' => 'b', 'schema' => 'msg', 'scopeField' => 'to', 'nudge' => '0', 'recipientProvider' => 'mo', 'bodyField' => 'text', 'subjectField' => 'about'],
			],
			$this->index()->inboxesFor(register: 'b', schema: 'msg')
		);
	}//end testInboxesAreIndexed()

	/**
	 * Unusable contributions and rules are skipped, and an entry served to two audiences is listed once.
	 *
	 * @return void
	 */
	public function testUnusableRulesAreSkippedAndDuplicatesListedOnce(): void {
		$messy = [
			'app'           => 'zaken',
			'notifications' => [
				'not a rule',
				['ruleKey' => 'r.unknown', 'collection' => 'nope'],
				['ruleKey' => 'r.claim', 'collection' => 'claimed', 'on' => ['field' => 'state']],
				['ruleKey' => 'r.addressed', 'collection' => 'claimed', 'on' => ['field' => 'state'], 'recipients' => ['field' => 'kvkNumber', 'claim' => 'kvk']],
			],
			'collections'   => [
				'not a collection',
				['label' => 'no id'],
				['id' => 'claimed', 'register' => 'r', 'schema' => 's', 'scopeClaim' => 'kvk'],
			],
		];
		$this->contributions(['citizen', 'business'], ['not an array', ['app' => ''], $messy]);
		$this->slugMaps(registers: [], schemas: []);

		$rules = $this->index()->changeRulesFor(register: 'r', schema: 's');

		$this->assertCount(1, $rules);
		$this->assertSame('r.addressed', $rules[0]['ruleKey']);
		$this->assertSame('kvkNumber', $rules[0]['recipientField']);
		$this->assertSame('kvk', $rules[0]['recipientClaim']);
		$this->assertSame('state', $rules[0]['field']);
		$this->assertSame('', $rules[0]['titleField']);
	}//end testUnusableRulesAreSkippedAndDuplicatesListedOnce()

	/**
	 * The portal's own message schema is recognised by slug or id.
	 *
	 * @return void
	 */
	public function testIsPortalMessage(): void {
		$this->contributions([], []);
		$this->slugMaps(registers: [3 => 'portaliq'], schemas: [4 => 'portalMessage']);
		$index = $this->index();

		$this->assertTrue($index->isPortalMessage(register: '3', schema: '4'));
		$this->assertTrue($index->isPortalMessage(register: 'portaliq', schema: 'portalMessage'));
		$this->assertFalse($index->isPortalMessage(register: '3', schema: '5'));
		$this->assertFalse($index->isPortalMessage(register: '2', schema: '4'));
	}//end testIsPortalMessage()

	/**
	 * An unreadable slug map is logged once and the index still matches plain names.
	 *
	 * @return void
	 */
	public function testAnUnreadableSlugMapFallsBackToPlainNames(): void {
		$this->contributions(['citizen'], [self::casesContribution()]);
		$this->container->method('get')->willThrowException(new RuntimeException('OpenRegister is not installed'));
		$this->logger->expects($this->exactly(2))->method('warning')->with(
			'Portaliq: could not read OpenRegister slugs for change rules',
			$this->callback(static fn (array $context): bool => in_array($context['map'], ['register', 'schema'], true) && $context['reason'] === 'OpenRegister is not installed')
		);
		$index = $this->index();

		$this->assertCount(1, $index->changeRulesFor(register: 'zaken-reg', schema: 'case'));
		$this->assertSame([], $index->changeRulesFor(register: '5', schema: '9'));
	}//end testAnUnreadableSlugMapFallsBackToPlainNames()

	/**
	 * The index under test.
	 *
	 * @return PortalChangeRuleIndex
	 */
	private function index(): PortalChangeRuleIndex {
		return new PortalChangeRuleIndex(registry: $this->registry, container: $this->container, logger: $this->logger);
	}//end index()

	/**
	 * Serve the given contributions to every given audience.
	 *
	 * @param array<int,string> $audiences     The audiences.
	 * @param array<int,mixed>  $contributions The contributions each one aggregates.
	 *
	 * @return void
	 */
	private function contributions(array $audiences, array $contributions): void {
		$this->registry->method('servedAudiences')->willReturn($audiences);
		$this->registry->expects($this->exactly(count($audiences)))->method('aggregateFor')
			->with($this->callback(static fn (array $subject): bool => $subject['trust'] === 'high' && $subject['subjectRef'] === '' && in_array($subject['audience'], $audiences, true)))
			->willReturn(['contributions' => $contributions]);
	}//end contributions()

	/**
	 * Let the container serve id-to-slug maps for registers and schemas.
	 *
	 * @param array<int,string> $registers Register ids to slugs.
	 * @param array<int,string> $schemas   Schema ids to slugs.
	 *
	 * @return void
	 */
	private function slugMaps(array $registers, array $schemas): void {
		$this->container->method('get')->willReturnCallback(
			static fn (string $class): object => new class(str_ends_with($class, 'RegisterMapper') === true ? $registers : $schemas) {
				/**
				 * Build the fake mapper.
				 *
				 * @param array<int,string> $map The id-to-slug map.
				 */
				public function __construct(private readonly array $map) {
				}

				/**
				 * The id-to-slug map.
				 *
				 * @return array<int,string>
				 */
				public function getIdToSlugMap(): array {
					return $this->map;
				}
			}
		);
	}//end slugMaps()

	/**
	 * An app with one change rule on its cases.
	 *
	 * @return array<string,mixed>
	 */
	private static function casesContribution(): array {
		return [
			'app'           => 'zaken',
			'notifications' => [
				['ruleKey' => 'status.changed', 'collection' => 'cases', 'on' => ['field' => 'status'], 'titleField' => 'title', 'messages' => ['closed' => ['subject' => 'Closed']]],
			],
			'collections'   => [
				['id' => 'cases', 'label' => 'My cases', 'register' => 'zaken-reg', 'schema' => 'case', 'scopeField' => 'owner'],
			],
		];
	}//end casesContribution()
}//end class

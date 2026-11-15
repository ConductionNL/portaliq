<?php

/**
 * Unit tests for queuing a letter to the resident's message box.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Notifications;

use OCA\Portaliq\BackgroundJob\MessageBoxDispatchJob;
use OCA\Portaliq\Service\Notifications\MessageBoxChannel;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A message box letter is queued only for a resident who did not opt out, of an
 * organisation that has a message box, from an inbox that names a provider.
 */
#[CoversClass(MessageBoxChannel::class)]
class MessageBoxChannelTest extends TestCase {

	/**
	 * The organisation config.
	 *
	 * @var PortalOrganisationConfigService&MockObject
	 */
	private PortalOrganisationConfigService&MockObject $orgConfig;

	/**
	 * The job list.
	 *
	 * @var IJobList&MockObject
	 */
	private IJobList&MockObject $jobList;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The channel under test.
	 *
	 * @var MessageBoxChannel
	 */
	private MessageBoxChannel $channel;

	/**
	 * Build the channel.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->orgConfig = $this->createMock(PortalOrganisationConfigService::class);
		$this->jobList   = $this->createMock(IJobList::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
		$this->channel   = new MessageBoxChannel(orgConfig: $this->orgConfig, jobList: $this->jobList, logger: $this->logger);
	}//end setUp()

	/**
	 * Only an explicit `enabled: false` opts a resident out.
	 *
	 * @return void
	 */
	public function testWants(): void {
		$this->assertTrue($this->channel->wants(account: []));
		$this->assertTrue($this->channel->wants(account: ['notificationPreferences' => 'x']));
		$this->assertTrue($this->channel->wants(account: ['notificationPreferences' => ['messageBox' => true]]));
		$this->assertTrue($this->channel->wants(account: ['notificationPreferences' => ['messageBox' => []]]));
		$this->assertTrue($this->channel->wants(account: ['notificationPreferences' => ['messageBox' => ['enabled' => 0]]]));
		$this->assertFalse($this->channel->wants(account: ['notificationPreferences' => ['messageBox' => ['enabled' => false]]]));
	}//end testWants()

	/**
	 * A letter for a resident is queued with its record, source and letter fields.
	 *
	 * @return void
	 */
	public function testALetterIsQueued(): void {
		$this->orgConfig->expects($this->once())->method('messageBox')->with('gemeente-x')->willReturn(['provider' => 'mijnoverheid']);
		$this->jobList->expects($this->once())->method('add')->with(
			MessageBoxDispatchJob::class,
			[
				'subjectRef'        => 'bsn:1',
				'organisation'      => 'gemeente-x',
				'audience'          => 'citizen',
				'appId'             => 'zaken',
				'ruleKey'           => 'message.created',
				'channel'           => 'messageBox',
				'recipientProvider' => 'mijnoverheid',
				'record'            => ['app' => 'zaken', 'collection' => 'letters', 'id' => 'r-1', 'label' => 'Letters'],
				'source'            => ['register' => 'reg', 'schema' => 'letter', 'scopeField' => 'owner'],
				'letterFields'      => ['body' => 'text', 'subject' => ''],
			]
		);
		$this->logger->expects($this->never())->method('warning');

		$this->assertTrue($this->channel->enqueue(account: self::account(), inbox: self::inbox(), recordId: 'r-1'));
	}//end testALetterIsQueued()

	/**
	 * Without a record, a resident or a provider nothing is asked or queued.
	 *
	 * @return void
	 */
	public function testIncompleteInputQueuesNothing(): void {
		$this->orgConfig->expects($this->never())->method('messageBox');
		$this->jobList->expects($this->never())->method('add');

		$this->assertFalse($this->channel->enqueue(account: self::account(), inbox: self::inbox(), recordId: ''));
		$this->assertFalse($this->channel->enqueue(account: ['organisation' => 'gemeente-x'], inbox: self::inbox(), recordId: 'r-1'));
		$this->assertFalse($this->channel->enqueue(account: self::account(), inbox: ['recipientProvider' => ''] + self::inbox(), recordId: 'r-1'));
	}//end testIncompleteInputQueuesNothing()

	/**
	 * An organisation without a message box, or a resident who opted out, gets nothing queued.
	 *
	 * @return void
	 */
	public function testNoMessageBoxOrOptedOutQueuesNothing(): void {
		$this->orgConfig->method('messageBox')->willReturnOnConsecutiveCalls(null, ['provider' => 'x']);
		$this->jobList->expects($this->never())->method('add');

		$this->assertFalse($this->channel->enqueue(account: self::account(), inbox: self::inbox(), recordId: 'r-1'));
		$optedOut = self::account() + ['notificationPreferences' => ['messageBox' => ['enabled' => false]]];
		$this->assertFalse($this->channel->enqueue(account: $optedOut, inbox: self::inbox(), recordId: 'r-1'));
	}//end testNoMessageBoxOrOptedOutQueuesNothing()

	/**
	 * A job list failure is logged and reported as not queued.
	 *
	 * @return void
	 */
	public function testAQueueFailureIsLogged(): void {
		$this->orgConfig->method('messageBox')->willReturn(['provider' => 'x']);
		$this->jobList->method('add')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('warning')->with(
			'Portaliq: message box send was not queued',
			['app' => 'zaken', 'reason' => 'db down']
		);

		$this->assertFalse($this->channel->enqueue(account: self::account(), inbox: self::inbox(), recordId: 'r-1'));
	}//end testAQueueFailureIsLogged()

	/**
	 * A resident account.
	 *
	 * @return array<string,mixed>
	 */
	private static function account(): array {
		return ['subjectRef' => 'bsn:1', 'organisation' => 'gemeente-x', 'audience' => 'citizen'];
	}//end account()

	/**
	 * An inbox entry.
	 *
	 * @return array<string,mixed>
	 */
	private static function inbox(): array {
		return [
			'app'               => 'zaken',
			'collection'        => 'letters',
			'label'             => 'Letters',
			'register'          => 'reg',
			'schema'            => 'letter',
			'scopeField'        => 'owner',
			'recipientProvider' => 'mijnoverheid',
			'bodyField'         => 'text',
		];
	}//end inbox()
}//end class

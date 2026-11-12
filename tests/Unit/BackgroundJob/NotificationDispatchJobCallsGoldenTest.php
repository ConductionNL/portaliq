<?php

/**
 * NotificationDispatchJobCallsGoldenTest
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\BackgroundJob
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

namespace OCA\Portaliq\Tests\Unit\BackgroundJob;

use OCA\Portaliq\BackgroundJob\NotificationDispatchJob;
use OCA\Portaliq\Service\Notifications\NotificationChannels;
use OCA\Portaliq\Service\Notifications\PortalNoticeLanguage;
use OCA\Portaliq\Service\PortalDeepLinkBuilder;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCA\Portaliq\Service\ScopedCollectionReaderInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;

/**
 * Every collaborator call one dispatch makes, in order, for each way a
 * dispatch can go: refused arguments, no account, a withdrawn or id-less
 * account, e-mail delivered, refused, failing and unaddressable, the failure
 * streak and its flag, failing log and flag writes, and the push.
 *
 * @covers \OCA\Portaliq\BackgroundJob\NotificationDispatchJob
 */
class NotificationDispatchJobCallsGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../Fixtures/Golden/notification-dispatch-job-calls.json';

	private const ARGUMENT = ['subjectRef' => 's1', 'organisation' => 'org-1', 'appId' => 'app-x', 'ruleKey' => 'message.created'];

	private const ACCOUNT = ['@self' => ['uuid' => 'acc-1'], 'email' => 'a@example.org', 'status' => 'active'];

	/**
	 * Everything the job did, in order.
	 *
	 * @var list<mixed>
	 */
	private array $log = [];

	/**
	 * Every scenario's calls are as recorded.
	 *
	 * @return void
	 */
	public function testEveryCallIsMadeAsRecorded(): void {
		$failed = ['ruleKey' => 'message.created', 'status' => 'failed', 'attempts' => 2, 'lastAttemptAt' => '2026-01-02'];
		$older = ['ruleKey' => 'message.created', 'status' => 'failed', 'attempts' => 7, 'lastAttemptAt' => '2026-01-01'];
		$sent = ['ruleKey' => 'message.created', 'status' => 'sent', 'attempts' => 0, 'lastAttemptAt' => '2026-01-03'];
		$push = ['ruleKey' => 'message.created', 'channel' => 'push', 'status' => 'failed', 'attempts' => 9, 'lastAttemptAt' => '2026-01-09'];
		$other = ['ruleKey' => 'other', 'status' => 'failed', 'attempts' => 9, 'lastAttemptAt' => '2026-01-09'];
		$scenarios = [
			'notAnArray' => ['argument' => 'x'],
			'missingSubject' => ['argument' => ['ruleKey' => 'r']],
			'missingRule' => ['argument' => ['subjectRef' => 's1']],
			'noAccount' => ['account' => null],
			'withdrawn' => ['account' => ['id' => 'acc-2', 'status' => 'void']],
			'noId' => ['account' => ['email' => 'a@example.org']],
			'delivered' => [],
			'deliveredFlatId' => ['account' => ['uuid' => 'u-9', 'id' => 7, 'email' => 'a@example.org']],
			'refused' => ['send' => ['a@example.org']],
			'throws' => ['send' => 'throw'],
			'noAddress' => ['account' => ['id' => 'acc-3']],
			'badAddress' => ['account' => ['id' => 'acc-3', 'email' => 'bad']],
			'streakCarried' => ['send' => 'throw', 'history' => [$older, $failed, $push, $other]],
			'streakAfterSent' => ['send' => 'throw', 'history' => [$failed, $sent]],
			'thresholdReached' => ['send' => 'throw', 'history' => [$failed], 'threshold' => '3'],
			'thresholdUnset' => ['send' => 'throw', 'history' => [$failed], 'threshold' => '0'],
			'flagCleared' => ['account' => [...self::ACCOUNT, 'needsAlternativeContact' => true]],
			'flagNotCleared' => ['account' => [...self::ACCOUNT, 'needsAlternativeContact' => 'yes']],
			'logWriteFails' => ['created' => null],
			'flagWriteFails' => ['send' => 'throw', 'history' => [$failed], 'updated' => null],
			'changeRule' => ['argument' => [...self::ARGUMENT, 'record' => ['id' => 'r1', 'label' => 'Case']], 'record' => ['id' => 'r1', 'label' => 'Case']],
			'pushOnly' => ['wants' => ['email' => false, 'push' => true], 'push' => 'sent'],
			'pushNoDevice' => ['wants' => ['email' => false, 'push' => true], 'push' => null],
			'pushAndEmail' => ['wants' => ['email' => true, 'push' => true], 'push' => 'failed'],
			'nothingWanted' => ['wants' => ['email' => false, 'push' => false]],
			'readerThrows' => ['account' => 'throw'],
		];

		$actual = [];
		foreach ($scenarios as $name => $scenario) {
			$this->log = [];
			$job = $this->job(scenario: $scenario);
			(new ReflectionMethod($job, 'run'))->invoke($job, ($scenario['argument'] ?? self::ARGUMENT));
			$actual[$name] = $this->log;
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode((string)json_encode($actual), true));
	}//end testEveryCallIsMadeAsRecorded()

	/**
	 * A double that logs every call by name and answers from `$answers`,
	 * itself for a fluent call, or an empty value.
	 *
	 * @param class-string         $class   What to double.
	 * @param array<string, mixed> $answers Answers by method, and `label`.
	 *
	 * @return MockObject
	 */
	private function recorder(string $class, array $answers): MockObject {
		$mock = $this->createMock($class);
		foreach ((new \ReflectionClass($class))->getMethods() as $method) {
			if ($method->isPublic() === false || $method->isStatic() === true || $method->isConstructor() === true) {
				continue;
			}

			$name = $method->getName();
			$names = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $method->getParameters());
			$mock->method($name)->willReturnCallback(function (mixed ...$args) use ($mock, $method, $name, $names, $answers): mixed {
				$this->log[] = [$answers['label'] . '.' . $name => $this->named(args: $args, names: $names)];
				if (array_key_exists($name, $answers) === true) {
					return is_callable($answers[$name]) ? $answers[$name](...$args) : $answers[$name];
				}

				return $this->emptyAnswer(mock: $mock, method: $method);
			});
		}

		return $mock;
	}//end recorder()

	/**
	 * The arguments of one call by parameter name, objects by class and the
	 * write time masked.
	 *
	 * @param array<int|string, mixed> $args  The arguments.
	 * @param list<string>             $names The parameter names.
	 *
	 * @return array<string, mixed>
	 */
	private function named(array $args, array $names): array {
		$named = [];
		foreach ($args as $i => $value) {
			if (is_array($value) === true && array_key_exists('lastAttemptAt', $value) === true) {
				$value['lastAttemptAt'] = (preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', (string)$value['lastAttemptAt']) === 1) ? 'NOW' : $value['lastAttemptAt'];
			}

			$named[$names[$i] ?? (string)$i] = is_object($value) ? (($value instanceof MockObject) ? 'double' : get_class($value)) : $value;
		}

		return $named;
	}//end named()

	/**
	 * The empty value a method's return type allows.
	 *
	 * @param MockObject       $mock   The double, for fluent calls.
	 * @param ReflectionMethod $method The method.
	 *
	 * @return mixed
	 */
	private function emptyAnswer(MockObject $mock, ReflectionMethod $method): mixed {
		$type = $method->getReturnType();
		if ($type === null || $type->allowsNull() === true || ($type instanceof ReflectionNamedType && $type->getName() === 'void')) {
			return null;
		}

		$name = ($type instanceof ReflectionNamedType) ? $type->getName() : 'mixed';
		$empty = ['string' => '', 'array' => [], 'bool' => true, 'int' => 0];
		if (array_key_exists($name, $empty) === true) {
			return $empty[$name];
		}

		return $mock;
	}//end emptyAnswer()

	/**
	 * The job for one scenario.
	 *
	 * @param array<string, mixed> $scenario What the collaborators answer.
	 *
	 * @return NotificationDispatchJob
	 */
	private function job(array $scenario): NotificationDispatchJob {
		$account = array_key_exists('account', $scenario) ? $scenario['account'] : self::ACCOUNT;
		$history = ($scenario['history'] ?? []);
		$send = ($scenario['send'] ?? []);
		$wants = ($scenario['wants'] ?? ['email' => true, 'push' => false]);
		$l10n = $this->recorder(IL10N::class, ['label' => 'l10n', 't' => static fn (string $text, mixed $parameters = []): string => $text . ' ' . json_encode($parameters)]);

		return new NotificationDispatchJob(
			time: $this->createMock(ITimeFactory::class),
			reader: $this->recorder(ScopedCollectionReaderInterface::class, [
				'label' => 'reader',
				'readCollection' => static function (string $register, string $schema) use ($account, $history): array {
					if ($schema !== 'portalAccount') {
						return $history;
					}

					if ($account === 'throw') {
						throw new RuntimeException('reader down');
					}

					return ($account === null) ? [] : [$account];
				},
			]),
			writer: $this->recorder(PortalObjectWriter::class, [
				'label' => 'writer',
				'createObject' => array_key_exists('created', $scenario) ? null : ['id' => 'n1'],
				'updateObject' => array_key_exists('updated', $scenario) ? null : ['id' => 'acc-1'],
			]),
			orgConfig: $this->recorder(PortalOrganisationConfigService::class, ['label' => 'orgConfig', 'resolve' => ['organisationName' => 'Org One']]),
			mailer: $this->recorder(IMailer::class, [
				'label' => 'mailer',
				'validateMailAddress' => static fn (string $email): bool => str_contains($email, '@'),
				'createMessage' => $this->recorder(IMessage::class, ['label' => 'message']),
				'send' => static fn (): array => ($send === 'throw') ? throw new RuntimeException('SMTP down') : $send,
			]),
			language: $this->recorder(PortalNoticeLanguage::class, ['label' => 'language', 'forOrganisation' => $l10n]),
			deepLinks: $this->recorder(PortalDeepLinkBuilder::class, [
				'label' => 'deepLinks',
				'forNotice' => static fn (string $organisation, array $record = []): string => 'https://site/' . $organisation . '/' . ($record['id'] ?? '-'),
			]),
			config: $this->recorder(IConfig::class, ['label' => 'config', 'getAppValue' => ($scenario['threshold'] ?? '2')]),
			logger: $this->recorder(LoggerInterface::class, ['label' => 'logger']),
			channels: $this->recorder(NotificationChannels::class, [
				'label' => 'channels',
				'record' => ($scenario['record'] ?? []),
				'kindOf' => 'kind-x',
				'wants' => static fn (array $account, string $kind, string $channel): bool => $wants[$channel],
				'push' => ($scenario['push'] ?? null),
			])
		);
	}//end job()
}//end class

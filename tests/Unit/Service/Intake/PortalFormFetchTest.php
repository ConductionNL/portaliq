<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\PortalFormFetch;
use OCA\Portaliq\Tests\Unit\Service\Intake\Fakes\ServiceFetchEventFake;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * data-lookups-and-checks-in-forms REQ-DIF-005: a step's fetch runs through
 * integriq's service fetch command from the server; its outputs fill the
 * declared fields and one output picks the check line; every failure is the
 * same "unavailable".
 *
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t05
 */
#[CoversClass(PortalFormFetch::class)]
class PortalFormFetchTest extends TestCase {

	/**
	 * The Kadaster ownership fetch of the FormulierVelden board.
	 *
	 * @return array<string, mixed>
	 */
	private function ownership(): array {
		return [
			'id' => 'eigenaar',
			'source' => 'kadaster-eigendom',
			'inputs' => ['postcode' => 'postcode', 'huisnummer' => 'huisnummer'],
			'outputs' => ['eigenaarNaam' => 'eigenaar'],
			'check' => [
				'label' => 'Wij controleren of u eigenaar bent',
				'output' => 'isEigenaar',
				'lines' => [
					'true' => 'Volgens het Kadaster staat dit pand op uw naam.',
					'false' => 'Volgens het Kadaster staat dit pand niet op uw naam.',
				],
			],
		];
	}

	/**
	 * A fetch over a dispatcher that answers each command with $answer.
	 *
	 * @param callable|null $answer     Called with the dispatched event; null leaves it unhandled.
	 * @param array         $dispatched Receives every dispatched event.
	 *
	 * @return PortalFormFetch
	 */
	private function fetcher(?callable $answer, array &$dispatched = []): PortalFormFetch {
		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use ($answer, &$dispatched): void {
				$dispatched[] = $event;
				if ($answer !== null) {
					$answer($event);
				}
			}
		);

		return new PortalFormFetch($events, $this->createMock(LoggerInterface::class), ServiceFetchEventFake::class);
	}

	public function testTheFetchSendsTheNamedAnswersAndFillsTheDeclaredFields(): void {
		$dispatched = [];
		$fetcher    = $this->fetcher(
			static fn (ServiceFetchEventFake $e) => $e->setOutputs(['eigenaarNaam' => 'S. de Vries', 'isEigenaar' => true, 'raw' => 'x'], '2026-11-17T12:00:00Z'),
			$dispatched
		);

		$answer = $fetcher->run(fetch: $this->ownership(), answers: ['postcode' => '3311AB', 'huisnummer' => '12', 'naam' => 'Sanne']);

		$this->assertSame(
			[
				'status' => PortalFormFetch::FETCHED,
				'values' => ['eigenaar' => 'S. de Vries'],
				'check' => 'Volgens het Kadaster staat dit pand op uw naam.',
				'fetchedAt' => '2026-11-17T12:00:00Z',
			],
			$answer
		);
		$this->assertCount(1, $dispatched);
		$this->assertSame('portaliq', $dispatched[0]->app);
		$this->assertSame('kadaster-eigendom', $dispatched[0]->fetch);
		$this->assertSame(['postcode' => '3311AB', 'huisnummer' => '12'], $dispatched[0]->inputs, 'only the declared answers go out');
	}

	public function testAFalseOutputPicksItsOwnLineAndAnUnlistedValueNone(): void {
		$no = $this->fetcher(static fn (ServiceFetchEventFake $e) => $e->setOutputs(['eigenaarNaam' => '', 'isEigenaar' => false], 'now'));
		$this->assertSame('Volgens het Kadaster staat dit pand niet op uw naam.', $no->run(fetch: $this->ownership(), answers: [])['check']);

		$odd = $this->fetcher(static fn (ServiceFetchEventFake $e) => $e->setOutputs(['isEigenaar' => 'misschien'], 'now'));
		$this->assertNull($odd->run(fetch: $this->ownership(), answers: [])['check']);
		$this->assertSame([], $odd->run(fetch: $this->ownership(), answers: [])['values'], 'an output the source left out fills nothing');
	}

	public function testARefusalAnUnhandledCommandAndAThrowAreUnavailable(): void {
		$refused = $this->fetcher(static fn (ServiceFetchEventFake $e) => $e->refuse('source-unavailable'));
		$this->assertSame(['status' => PortalFormFetch::UNAVAILABLE, 'reason' => 'source-unavailable'], $refused->run(fetch: $this->ownership(), answers: []));

		$unhandled = $this->fetcher(null);
		$this->assertSame(['status' => PortalFormFetch::UNAVAILABLE, 'reason' => 'unhandled'], $unhandled->run(fetch: $this->ownership(), answers: []));

		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willThrowException(new RuntimeException('3311AB'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Portaliq: form fetch request failed', ['exception' => RuntimeException::class]);
		$thrown = (new PortalFormFetch($events, $logger, ServiceFetchEventFake::class))->run(fetch: $this->ownership(), answers: []);
		$this->assertSame(['status' => PortalFormFetch::UNAVAILABLE, 'reason' => 'dispatch_failed'], $thrown);
	}

	public function testWithoutIntegriqNothingIsDispatched(): void {
		$events = $this->createMock(IEventDispatcher::class);
		$events->expects($this->never())->method('dispatchTyped');
		$fetcher = new PortalFormFetch($events, $this->createMock(LoggerInterface::class), 'OCA\\Nowhere\\NoSuchEvent');

		$this->assertSame(['status' => PortalFormFetch::UNAVAILABLE, 'reason' => 'not_installed'], $fetcher->run(fetch: $this->ownership(), answers: []));
	}

	public function testDeclaredFindsTheFetchOfTheNamedStepOnly(): void {
		$steps   = [
			['id' => 'adres', 'fields' => ['postcode']],
			['id' => 'pand', 'fields' => ['eigenaar'], 'fetch' => [$this->ownership()]],
		];
		$fetcher = $this->fetcher(null);

		$this->assertSame('kadaster-eigendom', $fetcher->declared(steps: $steps, step: 'pand', fetch: 'eigenaar')['source']);
		$this->assertNull($fetcher->declared(steps: $steps, step: 'adres', fetch: 'eigenaar'));
		$this->assertNull($fetcher->declared(steps: $steps, step: 'pand', fetch: 'anders'));
	}

	public function testFilledReplacesWhatTheBrowserSentWithTheSourcesAnswer(): void {
		$steps   = [['id' => 'pand', 'fields' => ['eigenaar'], 'fetch' => [$this->ownership()]], ['id' => 'plain', 'fields' => ['naam']]];
		$fetcher = $this->fetcher(static fn (ServiceFetchEventFake $e) => $e->setOutputs(['eigenaarNaam' => 'S. de Vries', 'isEigenaar' => true], 'now'));

		$this->assertSame(
			['eigenaar' => 'S. de Vries', 'naam' => 'Sanne'],
			$fetcher->filled(steps: $steps, answers: ['eigenaar' => 'Iemand anders', 'naam' => 'Sanne'])
		);

		$down = $this->fetcher(static fn (ServiceFetchEventFake $e) => $e->refuse('timeout'));
		$this->assertNull($down->filled(steps: $steps, answers: ['eigenaar' => 'x']));
		$this->assertSame(['naam' => 'n'], $down->filled(steps: [['id' => 'plain', 'fields' => ['naam']]], answers: ['naam' => 'n']), 'no fetch, nothing asked');
	}
}

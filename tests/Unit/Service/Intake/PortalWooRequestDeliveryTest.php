<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCA\Portaliq\Service\Intake\PortalWooRequestDelivery;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A Woo request a citizen sends through a portal form reaches opencatalogi's
 * own intake, through the provider the portal already locates, and only an
 * armed term with a date reads as armed.
 *
 * @covers \OCA\Portaliq\Service\Intake\PortalWooRequestDelivery
 *
 * @spec openspec/changes/woo-request-intake-through-opencatalogi/specs/portal-intake-form/spec.md#requirement-a-woo-request-form-is-delivered-to-opencatalogis-intake
 */
class PortalWooRequestDeliveryTest extends TestCase {

	public function testTheRequestReachesOpencatalogisProviderWithTheMomentItWasSent(): void {
		$provider = new class {
			/** @var array<int, mixed> */
			public array $calls = [];

			/**
			 * @param array<string, mixed> $answers
			 * @return array<string, string>
			 */
			public function receiveWooRequest(array $answers, string $receivedAt): array {
				$this->calls[] = [$answers, $receivedAt];
				return ['outcome' => 'armed', 'requestId' => 'req-1', 'reference' => 'WOO-2026-1A2B3C', 'dueAt' => '2026-11-02T09:00:00+00:00', 'message' => ''];
			}
		};

		$result = $this->delivery(installed: true, provider: $provider)->deliver(answers: ['requestedInformation' => 'Alles.'], submittedAt: '2026-10-05T09:00:00+00:00');

		$this->assertSame([[['requestedInformation' => 'Alles.'], '2026-10-05T09:00:00+00:00']], $provider->calls);
		$this->assertSame('armed', $result['outcome']);
		$this->assertSame('WOO-2026-1A2B3C', $result['reference']);
		$this->assertSame('2026-11-02T09:00:00+00:00', $result['dueAt']);

	}//end testTheRequestReachesOpencatalogisProviderWithTheMomentItWasSent()

	public function testWithoutOpencatalogiNothingIsLocatedAndTheOutcomeIsUnavailable(): void {
		$locator = $this->createMock(PortalProviderLocator::class);
		$locator->expects($this->never())->method('locate');
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->with('opencatalogi')->willReturn(false);

		$result = (new PortalWooRequestDelivery($appManager, $locator, $this->createMock(LoggerInterface::class)))
			->deliver(answers: [], submittedAt: '');

		$this->assertSame('unavailable', $result['outcome']);
		$this->assertSame('', $result['dueAt']);

	}//end testWithoutOpencatalogiNothingIsLocatedAndTheOutcomeIsUnavailable()

	public function testAnOpencatalogiWithoutTheMethodIsUnavailable(): void {
		$result = $this->delivery(installed: true, provider: new class {
		})->deliver(answers: [], submittedAt: '');

		$this->assertSame('unavailable', $result['outcome']);

	}//end testAnOpencatalogiWithoutTheMethodIsUnavailable()

	public function testNoProviderIsUnavailable(): void {
		$result = $this->delivery(installed: true, provider: null)->deliver(answers: [], submittedAt: '');

		$this->assertSame('unavailable', $result['outcome']);

	}//end testNoProviderIsUnavailable()

	public function testAThrowingProviderIsUnavailableNotACrash(): void {
		$provider = new class {
			/**
			 * @param array<string, mixed> $answers
			 * @return array<string, string>
			 */
			public function receiveWooRequest(array $answers, string $receivedAt): array {
				throw new RuntimeException('boom');
			}
		};

		$result = $this->delivery(installed: true, provider: $provider)->deliver(answers: [], submittedAt: '');

		$this->assertSame('unavailable', $result['outcome']);

	}//end testAThrowingProviderIsUnavailableNotACrash()

	public function testAnArmedAnswerWithoutADateIsNotArmed(): void {
		$result = $this->delivery(installed: true, provider: $this->answering(['outcome' => 'armed', 'requestId' => 'req-1', 'reference' => 'WOO-2026-1A2B3C']))
			->deliver(answers: [], submittedAt: '');

		$this->assertSame('not-armed', $result['outcome']);
		$this->assertSame('', $result['dueAt']);
		$this->assertSame('WOO-2026-1A2B3C', $result['reference']);

	}//end testAnArmedAnswerWithoutADateIsNotArmed()

	public function testANotArmedAnswerNeverCarriesADate(): void {
		$result = $this->delivery(installed: true, provider: $this->answering(['outcome' => 'not-armed', 'dueAt' => '2026-11-02T09:00:00+00:00', 'message' => 'no engine']))
			->deliver(answers: [], submittedAt: '');

		$this->assertSame('not-armed', $result['outcome']);
		$this->assertSame('', $result['dueAt']);
		$this->assertSame('no engine', $result['message']);

	}//end testANotArmedAnswerNeverCarriesADate()

	public function testAnUnknownOrMissingAnswerIsUnavailable(): void {
		$this->assertSame('unavailable', $this->delivery(installed: true, provider: $this->answering(['outcome' => 'maybe']))->deliver(answers: [], submittedAt: '')['outcome']);
		$this->assertSame('unavailable', $this->delivery(installed: true, provider: $this->answering(null))->deliver(answers: [], submittedAt: '')['outcome']);

	}//end testAnUnknownOrMissingAnswerIsUnavailable()

	/**
	 * A provider answering one fixed value.
	 *
	 * @param mixed $answer What it answers.
	 *
	 * @return object
	 */
	private function answering(mixed $answer): object {
		return new class($answer) {
			public function __construct(private mixed $answer) {
			}

			/**
			 * @param array<string, mixed> $answers
			 */
			public function receiveWooRequest(array $answers, string $receivedAt): mixed {
				return $this->answer;
			}
		};

	}//end answering()

	/**
	 * The delivery over an app manager and a locator double.
	 *
	 * @param bool $installed Whether opencatalogi is installed.
	 * @param object|null $provider What the locator finds.
	 *
	 * @return PortalWooRequestDelivery
	 */
	private function delivery(bool $installed, ?object $provider): PortalWooRequestDelivery {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->with('opencatalogi')->willReturn($installed);
		$locator = $this->createMock(PortalProviderLocator::class);
		$locator->method('locate')->with('opencatalogi')->willReturn($provider);

		return new PortalWooRequestDelivery($appManager, $locator, $this->createMock(LoggerInterface::class));

	}//end delivery()
}//end class

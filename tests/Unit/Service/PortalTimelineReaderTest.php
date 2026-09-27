<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCA\Portaliq\Service\PortalTimelineReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * portaliq#723: the case history a contributing app declares through a
 * collection's `timeline.provider` is read by calling that named method on
 * the app's provider with the object id, and handed on as the provider
 * returned it. portaliq decides nothing about which entries are public.
 *
 * @spec openspec/specs/portal-contribution-contract/spec.md
 */
class PortalTimelineReaderTest extends TestCase {

	public function testTheNamedProviderMethodIsCalledWithTheObjectId(): void {
		$provider = new TimelineProviderFixture();

		$entries = $this->reader(provider: $provider)->entries(appId: 'dossiq', method: 'caseTimeline', id: 'case-1');

		$this->assertSame(['case-1'], $provider->asked);
		// Handed on untouched: same entries, same order, nothing added.
		$this->assertSame(TimelineProviderFixture::ENTRIES, $entries);

	}//end testTheNamedProviderMethodIsCalledWithTheObjectId()

	public function testAMethodThatIsNotAPublicInstanceMethodIsNeverCalled(): void {
		$provider = new TimelineProviderFixture();
		$reader = $this->reader(provider: $provider);

		foreach (['hiddenTimeline', 'staticTimeline', 'noSuchMethod', 'getContribution', 'getAudiences', '__construct', 'twoArguments'] as $method) {
			$this->assertNull($reader->entries(appId: 'dossiq', method: $method, id: 'case-1'), $method . ' must not be callable');
		}

		$this->assertSame([], $provider->asked);

	}//end testAMethodThatIsNotAPublicInstanceMethodIsNeverCalled()

	public function testAnAppWithoutAProviderHasNoTimeline(): void {
		$this->assertNull($this->reader(provider: null)->entries(appId: 'elders', method: 'caseTimeline', id: 'case-1'));

	}//end testAnAppWithoutAProviderHasNoTimeline()

	public function testAProviderThatThrowsOrAnswersNonsenseHasNoTimeline(): void {
		$reader = $this->reader(provider: new TimelineProviderFixture());

		$this->assertNull($reader->entries(appId: 'dossiq', method: 'brokenTimeline', id: 'case-1'));
		$this->assertNull($reader->entries(appId: 'dossiq', method: 'scalarTimeline', id: 'case-1'));

	}//end testAProviderThatThrowsOrAnswersNonsenseHasNoTimeline()

	/**
	 * The reader over a locator that finds the given provider.
	 *
	 * @param object|null $provider The provider the locator finds.
	 *
	 * @return PortalTimelineReader
	 */
	private function reader(?object $provider): PortalTimelineReader {
		$locator = $this->getMockBuilder(PortalProviderLocator::class)
			->disableOriginalConstructor()
			->onlyMethods(['locate'])
			->getMock();
		$locator->method('locate')->willReturn($provider);

		return new PortalTimelineReader($locator, $this->createMock(LoggerInterface::class));

	}//end reader()

}//end class

/**
 * A contributing app's provider, with every shape of method the reader must
 * tell apart.
 */
class TimelineProviderFixture {

	public const ENTRIES = [
		['id' => 'e2', 'kind' => 'status-move', 'message' => 'In behandeling', 'occurredAt' => '2026-09-20T10:00:00+00:00'],
		['id' => 'e1', 'kind' => 'contact-moment', 'message' => 'Telefonisch gesproken', 'occurredAt' => '2026-09-18T09:00:00+00:00'],
	];

	/**
	 * The ids the provider was asked about.
	 *
	 * @var array<int, string>
	 */
	public array $asked = [];

	public function caseTimeline(string $caseId): array {
		$this->asked[] = $caseId;
		return self::ENTRIES;
	}

	public function getContribution(array $subject): array {
		$this->asked[] = 'getContribution';
		return [];
	}

	public function getAudiences(): array {
		$this->asked[] = 'getAudiences';
		return ['citizen'];
	}

	public static function staticTimeline(string $caseId): array {
		return self::ENTRIES;
	}

	public function twoArguments(string $caseId, string $other): array {
		$this->asked[] = 'twoArguments';
		return self::ENTRIES;
	}

	public function brokenTimeline(string $caseId): array {
		throw new RuntimeException('timeline store down');
	}

	public function scalarTimeline(string $caseId): string {
		return 'not a list';
	}

	private function hiddenTimeline(string $caseId): array {
		$this->asked[] = 'hiddenTimeline';
		return self::ENTRIES;
	}

}//end class

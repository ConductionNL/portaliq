<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\AnonymousContributions;
use OCA\Portaliq\Contribution\CollectionColumnsNormaliser;
use OCA\Portaliq\Contribution\ManifestValueNormaliser;
use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCA\Portaliq\Contribution\PublicRecordsNormaliser;
use OCA\Portaliq\Contribution\ValueLabelsNormaliser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Anonymous-audience aggregation and column normalisation.
 */
#[CoversClass(AnonymousContributions::class)]
#[CoversClass(CollectionColumnsNormaliser::class)]
#[UsesClass(ManifestValueNormaliser::class)]
#[UsesClass(ValueLabelsNormaliser::class)]
#[UsesClass(PublicRecordsNormaliser::class)]
#[UsesClass(\OCA\Portaliq\Contribution\AnonymousRead::class)]
class AnonymousColumnsTest extends TestCase {
	/**
	 * Build the aggregator over doubles.
	 *
	 * @param mixed $contribution What the provider returns (or an exception).
	 * @param bool  $normaliserThrows Whether the manifest normaliser throws.
	 * @param int   $logged Expected error log count.
	 *
	 * @return AnonymousContributions
	 */
	private function aggregator(mixed $contribution, bool $normaliserThrows=false, int $logged=0): AnonymousContributions {
		$locator = $this->createMock(PortalProviderLocator::class);
		if ($contribution instanceof \Throwable) {
			$locator->method('contributionOf')->willThrowException($contribution);
		} else {
			$locator->method('contributionOf')->willReturn($contribution);
		}

		$normaliser = $this->createMock(PortalManifestNormaliser::class);
		if ($normaliserThrows === true) {
			$normaliser->method('normalise')->willThrowException(new \RuntimeException('bad'));
		} else {
			$normaliser->method('normalise')->willReturnArgument(0);
		}

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly($logged))->method('error');

		return new AnonymousContributions($locator, $normaliser, $logger);
	}//end aggregator()

	/**
	 * Only anonymous collections and actions survive.
	 *
	 * @return void
	 */
	public function testKeepsOnlyAnonymousEntries(): void {
		$out = $this->aggregator([
			'collections' => [['id' => 'pub', 'anonymous' => true], ['id' => 'priv'], 'junk'],
			'actions' => [['id' => 'a1', 'anonymous' => true], ['id' => 'a2', 'anonymous' => 'yes']],
		])->forAudience(new \stdClass(), 'myapp', 'anonymous');

		$this->assertCount(1, $out);
		$this->assertSame('myapp', $out[0]['app']);
		$this->assertSame([['id' => 'pub', 'anonymous' => true]], $out[0]['collections']);
		$this->assertSame([['id' => 'a1', 'anonymous' => true]], $out[0]['actions']);
	}//end testKeepsOnlyAnonymousEntries()

	/**
	 * Nothing anonymous, a non-array answer or a failing provider is empty.
	 *
	 * @return void
	 */
	public function testEmptyCases(): void {
		$this->assertSame([], $this->aggregator(['collections' => [['id' => 'x']], 'actions' => 'bad'])->forAudience(new \stdClass(), 'a', 'anonymous'));
		$this->assertSame([], $this->aggregator('nope')->forAudience(new \stdClass(), 'a', 'anonymous'));
		$this->assertSame([], $this->aggregator(new \RuntimeException('down'), false, 1)->forAudience(new \stdClass(), 'a', 'anonymous'));
	}//end testEmptyCases()

	/**
	 * A failing manifest normaliser is logged and the raw anonymous entries are kept.
	 *
	 * @return void
	 */
	public function testNormaliserFailureIsLogged(): void {
		$out = $this->aggregator(['collections' => [['id' => 'pub', 'anonymous' => true]]], true, 1)->forAudience(new \stdClass(), 'myapp', 'anonymous');

		$this->assertCount(1, $out);
		$this->assertSame('pub', $out[0]['collections'][0]['id']);
	}//end testNormaliserFailureIsLogged()

	/**
	 * The normaliser sees the served audiences, the answer never does, an
	 * entry the normaliser stripped of its flag is filtered out again, and
	 * each failure logs its app and audience.
	 *
	 * @return void
	 */
	public function testTheNormaliserRoundAndTheLogsAreAsRecorded(): void {
		$seen    = [];
		$locator = $this->createMock(PortalProviderLocator::class);
		$locator->method('contributionOf')->willReturnCallback(static function (object $provider, array $subject): array {
			return ['collections' => [['id' => 'pub', 'anonymous' => true], ['id' => 'mixed', 'anonymous' => true, 'minTrust' => 'high']], 'actions' => [], 'asked' => $subject];
		});
		$normaliser = $this->createMock(PortalManifestNormaliser::class);
		$normaliser->method('normalise')->willReturnCallback(static function (array $contribution) use (&$seen): array {
			$seen = $contribution;
			unset($contribution['collections'][1]['anonymous']);
			return $contribution;
		});
		$out = (new AnonymousContributions($locator, $normaliser, $this->createMock(LoggerInterface::class)))->forAudience(new \stdClass(), 'myapp', 'parent', ['parent', 'staff']);

		$this->assertSame(['parent', 'staff'], $seen['servedAudiences']);
		$this->assertSame('myapp', $seen['app']);
		$this->assertSame(['audience' => 'parent'], $seen['asked']);
		$this->assertCount(1, $out);
		$this->assertArrayNotHasKey('servedAudiences', $out[0]);
		$this->assertSame([['id' => 'pub', 'anonymous' => true]], $out[0]['collections']);

		$stripping = $this->createMock(PortalManifestNormaliser::class);
		$stripping->method('normalise')->willReturnCallback(static function (array $contribution): array {
			unset($contribution['collections'][0]['anonymous'], $contribution['collections'][1]['anonymous']);
			return $contribution;
		});
		$this->assertSame([], (new AnonymousContributions($locator, $stripping, $this->createMock(LoggerInterface::class)))->forAudience(new \stdClass(), 'myapp', 'parent'));

		$logs   = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(static function (string $message, array $context) use (&$logs): void {
			$logs[] = [$message, $context];
		});
		$this->aggregatorWith(logger: $logger, contribution: new \RuntimeException('down'))->forAudience(new \stdClass(), 'myapp', 'parent');
		$this->aggregatorWith(logger: $logger, contribution: ['collections' => [['id' => 'pub', 'anonymous' => true]]], normaliserThrows: true)->forAudience(new \stdClass(), 'myapp', 'parent');
		$this->assertSame(
			[
				['Portaliq: contribution provider failed (anonymous aggregation)', ['app' => 'myapp', 'audience' => 'parent', 'reason' => 'down']],
				['Portaliq: manifest normalisation failed (anonymous aggregation)', ['app' => 'myapp', 'audience' => 'parent', 'reason' => 'bad']],
			],
			$logs
		);
	}//end testTheNormaliserRoundAndTheLogsAreAsRecorded()

	/**
	 * The aggregator over doubles, with a given logger.
	 *
	 * @param LoggerInterface $logger           The logger.
	 * @param mixed           $contribution     What the provider returns (or an exception).
	 * @param bool            $normaliserThrows Whether the manifest normaliser throws.
	 *
	 * @return AnonymousContributions
	 */
	private function aggregatorWith(LoggerInterface $logger, mixed $contribution, bool $normaliserThrows=false): AnonymousContributions {
		$locator = $this->createMock(PortalProviderLocator::class);
		if ($contribution instanceof \Throwable) {
			$locator->method('contributionOf')->willThrowException($contribution);
		} else {
			$locator->method('contributionOf')->willReturn($contribution);
		}

		$normaliser = $this->createMock(PortalManifestNormaliser::class);
		if ($normaliserThrows === true) {
			$normaliser->method('normalise')->willThrowException(new \RuntimeException('bad'));
		} else {
			$normaliser->method('normalise')->willReturnArgument(0);
		}

		return new AnonymousContributions($locator, $normaliser, $logger);
	}//end aggregatorWith()

	/**
	 * Columns keep sound entries, default the render kind and bound link labels.
	 *
	 * @return void
	 */
	public function testNormaliseColumns(): void {
		$n = new CollectionColumnsNormaliser(new ManifestValueNormaliser());

		$this->assertSame(['id' => 1], $n->normaliseColumns(['id' => 1]));
		$this->assertSame(['id' => 1], $n->normaliseColumns(['id' => 1, 'columns' => 'bad']));

		$out = $n->normaliseColumns(['columns' => [
			['field' => 'name', 'label' => 'Name', 'render' => 'badge'],
			['field' => 'site', 'render' => 'link', 'linkLabel' => ' Open '],
			['field' => 'qr', 'render' => 'qr', 'linkLabel' => str_repeat('a', 61)],
			['field' => 'plain', 'render' => 'unknown', 'linkLabel' => 'ignored'],
			['field' => ''],
			['label' => 'no field'],
			'junk',
		]])['columns'];

		$this->assertSame(['name', 'site', 'qr', 'plain'], array_column($out, 'field'));
		$this->assertSame('badge', $out[0]['render']);
		$this->assertSame('Name', $out[0]['label']);
		$this->assertSame('Open', $out[1]['linkLabel']);
		$this->assertArrayNotHasKey('linkLabel', $out[2]);
		$this->assertSame('text', $out[3]['render']);
		$this->assertArrayNotHasKey('linkLabel', $out[3]);
	}//end testNormaliseColumns()
}

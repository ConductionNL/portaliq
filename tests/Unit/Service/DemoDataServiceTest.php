<?php

namespace Unit\Service;

use OCA\Portaliq\Service\DemoDataService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * ADR-111 rule 1 — the shipped demo dataset.
 *
 * Every failure path here exists because the caller reports the outcome to an
 * operator who just asked for demo data: "nothing happened" must never be
 * presentable as success, so each one must THROW rather than return empty.
 */
class DemoDataServiceTest extends TestCase {
	private string $appPath;
	private IAppManager $appManager;
	private ContainerInterface $container;

	protected function setUp(): void {
		$this->appPath = sys_get_temp_dir() . '/or-demo-' . uniqid();
		mkdir($this->appPath . '/lib/Settings', 0777, true);

		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('getAppPath')->willReturn($this->appPath);
		$this->appManager->method('getAppVersion')->willReturn('1.2.3');
		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);

		$this->container = $this->createMock(ContainerInterface::class);
	}

	protected function tearDown(): void {
		$file = $this->descriptor();
		if (is_file($file) === true) {
			unlink($file);
		}

		@rmdir($this->appPath . '/lib/Settings');
		@rmdir($this->appPath . '/lib');
		@rmdir($this->appPath);
	}

	private function descriptor(): string {
		return $this->appPath . '/lib/Settings/portaliq_mock_register.json';
	}

	private function service(): DemoDataService {
		return new DemoDataService(
			$this->appManager,
			$this->container,
			$this->createMock(LoggerInterface::class)
		);
	}

	public function testIsAvailableIsFalseWithoutADescriptor(): void {
		$this->assertFalse($this->service()->isAvailable());
	}

	public function testIsAvailableIsTrueWithADescriptor(): void {
		file_put_contents($this->descriptor(), '{}');

		$this->assertTrue($this->service()->isAvailable());
	}

	public function testDecliningIsOfferedEvenWhenNoDatasetShips(): void {
		// 🔴 "NO THANKS" HAS TO BE SAYABLE. Every app in this fleet implemented a
		// `skip-demo-data` action that no manifest step could reach, so the step
		// stayed outstanding and CnAppRoot reopened the wizard over every page
		// unless the operator imported data they did not want.
		$choices = $this->service()->listChoices();

		$this->assertSame(['none'], array_column($choices, 'id'));
		$this->assertNotSame('', $choices[0]['description']);
		$this->assertNotSame('', $choices[0]['icon']);
	}

	public function testTheShippedDatasetIsOfferedWithTheCountItActuallyCarries(): void {
		// The card promises a number, so the number has to come from the file
		// that will be imported rather than from a manifest that could disagree
		// with it.
		file_put_contents(
			$this->descriptor(),
			json_encode(['components' => ['objects' => [['a' => 1], ['b' => 2], ['c' => 3]]]])
		);

		$choices = $this->service()->listChoices();

		$this->assertSame(['none', 'demo'], array_column($choices, 'id'));
		$this->assertSame(3, $choices[1]['objectCount']);
		$this->assertNotSame('', $choices[1]['label']);
		$this->assertNotSame('', $choices[1]['description']);
	}

	public function testAMalformedDescriptorOffersNothingRatherThanAnImportThatCannotRun(): void {
		file_put_contents($this->descriptor(), 'not json at all');

		$this->assertSame(['none'], array_column($this->service()->listChoices(), 'id'));
	}

	public function testTheOfferedDescriptionCarriesNoNumber(): void {
		// 🔴 THE WIZARD TRANSLATES A CARD'S DESCRIPTION BY LITERAL LOOKUP. A
		// count interpolated into the sentence would make it untranslatable and
		// leave a Dutch operator reading English. The count travels separately,
		// as `objectCount`.
		file_put_contents(
			$this->descriptor(),
			json_encode(['components' => ['objects' => [['a' => 1]]]])
		);

		$demo = $this->service()->listChoices()[1];

		$this->assertDoesNotMatchRegularExpression('/\d/', $demo['description']);
	}

	public function testADescriptorWithNoObjectsBlockOffersTheSetWithNoCount(): void {
		// A descriptor can ship schemas and no objects. That is a real dataset
		// with nothing to count, not a broken file, so it stays on offer.
		file_put_contents($this->descriptor(), json_encode(['components' => ['schemas' => []]]));

		$choices = $this->service()->listChoices();

		$this->assertSame(['none', 'demo'], array_column($choices, 'id'));
		$this->assertSame(0, $choices[1]['objectCount']);
	}

	public function testInstallThrowsWhenNoDatasetShips(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/No demo dataset/');

		$this->service()->install();
	}

	public function testInstallThrowsOnInvalidJson(): void {
		file_put_contents($this->descriptor(), 'not json at all');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/not valid JSON/');

		$this->service()->install();
	}

	public function testInstallNamesTheMissingAppWhenOpenRegisterIsAbsent(): void {
		file_put_contents($this->descriptor(), '{"components":{"objects":[]}}');
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('getAppPath')->willReturn($this->appPath);
		$this->appManager->method('getInstalledApps')->willReturn([]);

		// 🔴 The message must NAME the missing app. Asking the container for a
		// class from an app that is not installed otherwise surfaces as an error
		// about a class the operator never mentioned.
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/OpenRegister/');

		$this->service()->install();
	}

	public function testInstallReportsWhatLandedNotWhatWasAskedFor(): void {
		file_put_contents(
			$this->descriptor(),
			json_encode(['components' => ['objects' => [['a' => 1], ['b' => 2], ['c' => 3]]]])
		);

		// 🔴 THE PARAMETER NAMES ARE THE CONTRACT. install() calls this with
		// named arguments, so a fake whose parameters are named differently
		// fails at the call site rather than validating anything.
		$importer = new class {
			public array $seen = [];

			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				$this->seen = ['appId' => $appId, 'version' => $version, 'force' => $force];

				// Deliberately reports FEWER than the file holds: an object whose
				// schema does not resolve is SKIPPED, not errored. The operator
				// must be told what landed and what did not — the file's count
				// alone read "Imported 39" over a run that seeded nothing
				// visible (WOO-558).
				return [
					'registers' => [1],
					'schemas'   => [1, 1],
					'objects'   => [['id' => 'x'], ['id' => 'y']],
					'skipped'   => ['objects' => 1],
				];
			}
		};
		$this->container->method('get')->willReturn($importer);

		$result = $this->service()->install();

		$this->assertSame(2, $result['objects']);
		$this->assertSame(3, $result['declared']);
		$this->assertSame(1, $result['skipped']);
		$this->assertSame(1, $result['registers']);
		$this->assertSame(2, $result['schemas']);

		// Its own configuration namespace, so a demo import cannot mask — or be
		// masked by — a pending real configuration update.
		$this->assertSame('portaliq.demo', $importer->seen['appId']);
		$this->assertTrue($importer->seen['force']);
	}

	public function testAnObjectTheImporterDroppedUncountedIsStillReportedAsSkipped(): void {
		// OpenRegister drops an object whose register or schema it cannot find
		// BEFORE its counted skip path, so its own `skipped` says 0 while the
		// object never landed. The gap between declared and landed is the
		// number the operator needs.
		file_put_contents(
			$this->descriptor(),
			json_encode(['components' => ['objects' => [['a' => 1], ['b' => 2], ['c' => 3], ['d' => 4]]]])
		);
		$importer = new class {
			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				return ['objects' => [['id' => 'x']], 'skipped' => ['objects' => 0]];
			}
		};
		$this->container->method('get')->willReturn($importer);

		$result = $this->service()->install();

		$this->assertSame(1, $result['objects']);
		$this->assertSame(3, $result['skipped']);
	}

	public function testARerunThatLeavesEveryObjectAloneStillCountsAsLanded(): void {
		// Newer OpenRegister reports an object it found already present and
		// identical under `unchanged` rather than `objects`. That is landed data;
		// a second click on "Run" must not turn into a failure.
		file_put_contents($this->descriptor(), json_encode(['components' => ['objects' => [['a' => 1], ['b' => 2]]]]));
		$importer = new class {
			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				return ['objects' => [], 'unchanged' => ['objects' => 2], 'skipped' => ['objects' => 0]];
			}
		};
		$this->container->method('get')->willReturn($importer);

		$result = $this->service()->install();

		$this->assertSame(2, $result['objects']);
		$this->assertSame(0, $result['skipped']);
	}

	public function testInstallThrowsWhenADescriptorThatDeclaresObjectsSeedsNone(): void {
		// 🔴 "IMPORTED 39" OVER AN EMPTY RESULT IS THE BUG. When every object was
		// skipped the operator asked for demo data and got none: that is a
		// failure to report, not a success with a footnote. Same rule as
		// OpenRegister's own RegisterDescriptorService::reimport().
		file_put_contents(
			$this->descriptor(),
			json_encode(['components' => ['objects' => [['a' => 1], ['b' => 2], ['c' => 3]]]])
		);
		$importer = new class {
			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				return ['registers' => [1], 'schemas' => [], 'objects' => [], 'skipped' => ['objects' => 3]];
			}
		};
		$this->container->method('get')->willReturn($importer);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/declares 3 object\(s\) but OpenRegister imported none/');

		$this->service()->install();
	}

	public function testADescriptorWithoutObjectsMayLandNothingWithoutFailing(): void {
		// Zero declared, zero landed is a no-op, not a broken import.
		file_put_contents($this->descriptor(), json_encode(['components' => ['objects' => []]]));
		$importer = new class {
			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				return ['registers' => [1], 'objects' => []];
			}
		};
		$this->container->method('get')->willReturn($importer);

		$this->assertSame(0, $this->service()->install()['objects']);
	}

	public function testTheShippedDescriptorDeclaresTheRealRegisterAndNoSchemaBlock(): void {
		// 🔴 THE MOCK MUST NOT CARRY ITS OWN SCHEMA DEFINITIONS. Imported under
		// this service's own configuration identity, OpenRegister resolves such
		// copies per APPLICATION and creates a second schema set next to the
		// real one; the objects then land where no register links them
		// (WOO-556 B3 / WOO-558: schemas 80-92 beside 67-79, 30 objects
		// invisible). The generator (hydra-gates generate_mock_register.py)
		// emits registers + objects only; this pins that shape in the repo.
		$settings = dirname(__DIR__, 3) . '/lib/Settings';
		$mock     = json_decode((string)file_get_contents($settings . '/portaliq_mock_register.json'), true);
		$real     = json_decode((string)file_get_contents($settings . '/portaliq_register.json'), true);

		$this->assertSame('mock', $mock['x-openregister']['type']);
		$this->assertArrayNotHasKey('schemas', $mock['components']);
		$this->assertArrayHasKey('objects', $mock['components']);
		$this->assertNotSame([], $mock['components']['objects']);

		// Every object targets the REAL register (same slug) and a schema the
		// real descriptor defines, so resolution by slug lands on the installed
		// set instead of skipping the object.
		$realSlug    = array_key_first($real['components']['registers']);
		$realSchemas = array_keys($real['components']['schemas']);
		$this->assertSame([$realSlug], array_keys($mock['components']['registers']));
		foreach ($mock['components']['objects'] as $object) {
			$this->assertSame($realSlug, $object['@self']['register']);
			$this->assertContains($object['@self']['schema'], $realSchemas);
		}
	}
}

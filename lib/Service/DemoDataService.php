<?php
/**
 * Portaliq DemoDataService.
 *
 * Imports `lib/Settings/portaliq_mock_register.json` — a `type: mock` descriptor generated from this
 * app's own schemas by `hydra-gates/scripts/lib/generate_mock_register.py`, so
 * every value is conformant BY CONSTRUCTION rather than written to look
 * plausible.
 *
 * 🔴 ON DEMAND ONLY, NEVER ON INSTALL. A mock register has no Repair step: demo
 * data is something an operator asks for from the setup wizard, and an install
 * that silently seeds example objects into a production instance is a defect,
 * not a convenience.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\AppInfo\Application;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Imports the shipped demo dataset on request.
 *
 * @spec exclude Demo-data import; ADR-111 rule 1, no per-app behavioural spec.
 */
class DemoDataService {
	/**
	 * App-relative path to the generated mock descriptor.
	 *
	 * 🔴 THE DESCRIPTOR CARRIES NO `schemas` BLOCK, and must not grow one. It
	 * declares the app's REAL register (same slug) and its objects; OpenRegister
	 * resolves each object's schema by slug against what the real descriptor
	 * already installed. A copy of the schema definitions, imported under this
	 * service's own configuration identity, is resolved per APPLICATION and so
	 * became a second, parallel schema set (ids 80-92 next to 67-79 on the
	 * WOO-556 test instance) that the register never linked — 30 objects landed
	 * where no screen could find them (WOO-558). Regenerate with
	 * `hydra-gates/scripts/lib/generate_mock_register.py <app-dir> --out …`;
	 * `--check` in the gates guards the shape.
	 *
	 * @var string
	 */
	private const DESCRIPTOR = '/lib/Settings/portaliq_mock_register.json';

	/**
	 * Configuration identity for the demo import.
	 *
	 * 🔴 ITS OWN NAMESPACE, not the app id. Sharing the app's identity would make
	 * the demo import and the real configuration import share one version gate, so
	 * installing demo data could mask a pending configuration update — or be
	 * masked by one.
	 *
	 * @var string
	 */
	private const CONFIG_APP_ID = Application::APP_ID . '.demo';

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager Resolves this app's path and version.
	 * @param ContainerInterface $container  Resolves OpenRegister's importer.
	 * @param LoggerInterface    $logger     Records what was imported.
	 * @param PortalRegisterContext $registerContext Points OpenRegister at this app's own schemas for the presence count.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly PortalRegisterContext $registerContext,
	) {
	}//end __construct()

	/**
	 * Whether this app ships a demo dataset at all.
	 *
	 * @return boolean True when the descriptor is present on disk.
	 *
	 * @spec exclude Demo-data availability probe; ADR-111 rule 1 has no per-app behavioural spec.
	 */
	public function isAvailable(): bool {
		return is_file($this->descriptorPath()) === true;
	}//end isAvailable()

	/**
	 * The answer that means "plant nothing".
	 *
	 * 🔴 NOT THE ABSENCE OF AN ANSWER. An operator who declines has FINISHED the
	 * step; a step that can never be marked done reopens the wizard over every
	 * page (nextcloud-vue#806).
	 *
	 * @var string
	 */
	public const NONE_DATASET = 'none';

	/**
	 * The id of the dataset this app ships.
	 *
	 * @var string
	 */
	public const DEMO_DATASET = 'demo';

	/**
	 * Every answer the wizard's choice step may offer, declining included.
	 *
	 * 🔴 THE SERVER OWNS THIS LIST, AND THAT IS THE POINT. The step declares
	 * `optionsSource: datasets` and no options of its own, so the label, the
	 * description and the object count come from the descriptor that will
	 * actually be imported. A manifest that restated them could disagree with
	 * what lands, and nothing would notice.
	 *
	 * @return array<int, array{id: string, label: string, description: string, objectCount: integer, icon: string}> The answers.
	 *
	 * @spec exclude Demo-data choice list; ADR-111 rule 1 has no per-app behavioural spec.
	 */
	public function listChoices(): array {
		$choices = [
			[
				'id'          => self::NONE_DATASET,
				'label'       => 'None, I will set this up myself',
				'description' => 'Nothing is imported. You start with an empty app and add your own data.',
				'objectCount' => 0,
				'icon'        => 'CloseCircleOutline',
			],
		];

		$objects = $this->shippedObjectCount();
		if ($objects !== null) {
			$choices[] = [
				'id'    => self::DEMO_DATASET,
				'label' => 'Example data',
				// 🔴 NO NUMBER IN THIS SENTENCE. The wizard runs a card's
				// description through the app's translation function, which is a
				// literal lookup, so an interpolated count would make the string
				// untranslatable and leave a Dutch operator reading English. The
				// count travels as `objectCount` and the card renders it as a
				// stat, with a label the library translates.
				'description' => (
					'Sample values for every schema this app supplies, generated from the schemas '
					. 'themselves. It shows the lists, detail pages and dashboards working rather '
					. 'than telling a story. Safe to run more than once, and you can delete it '
					. 'afterwards.'
				),
				'objectCount' => $objects,
				'icon'        => 'DatabaseOutline',
			];
		}

		return $choices;

	}//end listChoices()

	/**
	 * How many objects the shipped descriptor carries, or null when it ships none.
	 *
	 * Counted from the FILE, so the card promises the number that will actually
	 * be imported. A missing or malformed descriptor returns null and the app
	 * then offers only "None" — honest, rather than an import that cannot run.
	 *
	 * @return integer|null The object count, or null when there is no usable descriptor.
	 */
	private function shippedObjectCount(): ?int {
		$path = $this->descriptorPath();
		if (is_file($path) === false) {
			return null;
		}

		$raw = file_get_contents($path);
		if ($raw === false) {
			return null;
		}

		$data = json_decode($raw, true);
		if (is_array($data) === false) {
			return null;
		}

		$components = ($data['components'] ?? []);
		if (is_array($components) === false || is_array(($components['objects'] ?? null)) === false) {
			return 0;
		}

		return count($components['objects']);

	}//end shippedObjectCount()

	/**
	 * Import the demo dataset.
	 *
	 * 🔴 THROWS RATHER THAN RETURNING A QUIET FAILURE. The caller reports the
	 * outcome to an operator who just asked for this, so "nothing happened" must
	 * not be presentable as success.
	 *
	 * 🔴 COUNTS WHAT LANDED, NOT WHAT WAS ASKED FOR. OpenRegister SKIPS an object
	 * whose schema it cannot resolve instead of failing the import, so a count
	 * taken from the file reports success for a run that seeded nothing — the
	 * wizard said "Imported 39 demo object(s)" while every one of them had been
	 * skipped (WOO-558). `objects` is therefore the importer's own tally of
	 * objects it wrote (or found already present); the file's count travels
	 * separately as `declared`, and the gap as `skipped`, so the operator sees
	 * the discrepancy instead of a number that merely repeats their request.
	 * A descriptor that declares objects and seeds NONE of them is a failure,
	 * the same rule OpenRegister's own RegisterDescriptorService applies.
	 *
	 * @return array{objects: integer, declared: integer, skipped: integer, present: integer, registers: integer, schemas: integer}
	 *   `objects` = what landed, `declared` = what the file holds, `skipped` =
	 *   what the importer refused, `present` = what the demo schemas hold when
	 *   nothing landed, plus the register and schema tallies.
	 *
	 * @throws RuntimeException When the descriptor is missing, unreadable, or
	 *   OpenRegister is absent — or when it declares objects and none landed.
	 *
	 * @spec exclude Demo-data import; ADR-111 rule 1 has no per-app behavioural spec.
	 */
	public function install(): array {
		$path = $this->descriptorPath();
		if (is_file($path) === false) {
			throw new RuntimeException('No demo dataset ships with this app (' . self::DESCRIPTOR . ' not found).');
		}

		$raw = file_get_contents($path);
		if ($raw === false) {
			throw new RuntimeException('The demo dataset could not be read: ' . $path);
		}

		$data = json_decode($raw, true);
		if (is_array($data) === false) {
			throw new RuntimeException('The demo dataset is not valid JSON: ' . $path);
		}

		// The number ASKED FOR comes from the file; the number that LANDED comes
		// from the importer. They differ whenever OpenRegister skips an object
		// whose schema it cannot resolve, and that gap is exactly the condition
		// an operator must be able to see.
		$declared = 0;
		$components = ($data['components'] ?? []);
		if (is_array($components) === true && is_array(($components['objects'] ?? null)) === true) {
			$declared = count($components['objects']);
		}

		$result = $this->configurationService()->importFromApp(
			appId: self::CONFIG_APP_ID,
			data: $data,
			version: $this->appManager->getAppVersion(Application::APP_ID),
			force: true
		);

		// `objects` lists what the importer wrote; newer OpenRegister versions
		// also count what they deliberately left alone (`unchanged`, an object
		// already present and identical), which is landed data too — a re-run
		// of the demo import must not read as a failure.
		$landed = count((array)($result['objects'] ?? []));
		$landed += (int)($result['unchanged']['objects'] ?? 0);

		// Everything declared that did not land was skipped, whatever the
		// importer's own counter says: an object whose register or schema it
		// cannot find is dropped BEFORE the counted path (measured on the
		// WOO-556 instance: 54 declared, 30 landed, "9 skipped" — the 15 with
		// no installed schema were in neither number).
		$skipped = max($declared - $landed, (int)($result['skipped']['objects'] ?? 0));

		// A run that wrote nothing is a FAILURE only when the demo schemas are
		// empty afterwards. OpenRegister (before d1af968b7) skips an object that
		// already exists at the same version with a bare `continue` — counted
		// nowhere — so a second click on "Run" on a seeded instance reports zero
		// landed while every object is present (review of #499). The presence
		// count tells those two apart: present → the operator gets "already
		// there"; empty → the import really did nothing and says so loudly.
		$present = 0;
		if ($declared > 0 && $landed === 0) {
			$present = $this->presentObjects(objects: $components['objects']);
			if ($present === 0) {
				throw new RuntimeException(
					'The demo dataset declares ' . $declared . ' object(s) but OpenRegister imported none of them'
					. ' and the demo schemas hold no objects (' . $skipped . ' skipped — their schema could not be'
					. ' resolved or they were rejected; see the Nextcloud log).'
				);
			}
		}

		$imported = [
			'objects'   => $landed,
			'declared'  => $declared,
			'skipped'   => $skipped,
			'present'   => $present,
			'registers' => count((array)($result['registers'] ?? [])),
			'schemas'   => count((array)($result['schemas'] ?? [])),
		];

		$this->logger->info(
			'[DemoDataService] imported demo data: '
			. $imported['objects'] . ' of ' . $imported['declared'] . ' object(s) landed ('
			. $imported['skipped'] . ' skipped, ' . $imported['present'] . ' present in the demo schemas), '
			. $imported['registers'] . ' register(s), '
			. $imported['schemas'] . ' schema(s).',
			['app' => Application::APP_ID]
		);

		return $imported;
	}//end install()

	/**
	 * How many objects this app's register holds in the schemas the demo
	 * descriptor targets — the "is the data there?" question OpenRegister's
	 * import result cannot answer for objects it left alone.
	 *
	 * Counts per DISTINCT schema slug of the declared objects, through
	 * PortalRegisterContext so the count is scoped to portaliq's own schemas
	 * (a bare slug like `page` resolves to another app's schema on a shared
	 * instance). Fails soft to 0: an unreachable OpenRegister must surface as
	 * "nothing there", never as a second exception on top of the import.
	 *
	 * @param array<int, mixed> $objects The descriptor's `components.objects`.
	 *
	 * @return int The number of objects present, 0 when nothing (or nothing countable).
	 *
	 * @spec exclude Demo-data presence probe; ADR-111 rule 1 has no per-app behavioural spec.
	 */
	private function presentObjects(array $objects): int {
		$slugs = [];
		foreach ($objects as $object) {
			$slug = (string)($object['@self']['schema'] ?? '');
			if ($slug !== '') {
				$slugs[$slug] = true;
			}
		}

		if ($slugs === []) {
			return 0;
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $unavailable) {
			$this->logger->warning('[DemoDataService] cannot count present demo objects: ' . $unavailable->getMessage());
			return 0;
		}

		$present = 0;
		foreach (array_keys($slugs) as $slug) {
			try {
				if ($this->registerContext->apply(objectService: $objectService, schemaSlug: $slug) === false) {
					continue;
				}

				$present += (int)$objectService->count(config: []);
			} catch (Throwable $countFailed) {
				$this->logger->warning('[DemoDataService] count failed for schema ' . $slug . ': ' . $countFailed->getMessage());
			}
		}

		return $present;
	}//end presentObjects()

	/**
	 * Absolute path to the shipped descriptor.
	 *
	 * @return string The path.
	 */
	private function descriptorPath(): string {
		return $this->appManager->getAppPath(Application::APP_ID) . self::DESCRIPTOR;
	}//end descriptorPath()

	/**
	 * OpenRegister's configuration importer.
	 *
	 * 🔴 A CROSS-APP CLASS IS A RUNTIME LOOKUP. OpenRegister may not be installed,
	 * and asking the container for a class from a missing app raises something the
	 * caller cannot act on. Check first and say which app is missing.
	 *
	 * 🔴 THE RETURN TYPE IS `object`, NOT THE CLASS, AND THAT IS THE POINT. Naming
	 * a class from an OPTIONAL app in a native return type makes PHP resolve it
	 * whenever this method returns, so on an instance without OpenRegister the
	 * failure is a TypeError about a class nobody mentioned instead of the
	 * RuntimeException above that names the missing app.
	 *
	 * @return object The importer — an OCA\OpenRegister\Service\ConfigurationService.
	 *
	 * @psalm-return \OCA\OpenRegister\Service\ConfigurationService
	 *
	 * @throws RuntimeException When OpenRegister is not installed.
	 */
	private function configurationService(): object {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			throw new RuntimeException('Demo data needs OpenRegister, which is not installed.');
		}

		return $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
	}//end configurationService()
}//end class

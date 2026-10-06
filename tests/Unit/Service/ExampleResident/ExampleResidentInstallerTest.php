<?php

/**
 * Unit tests for ExampleResidentInstaller, ExampleResidentRemover, ExampleResidentValues and ExampleResidentCatalogue.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\ExampleResident;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Portaliq\Service\ExampleResident\ExampleResidentCatalogue;
use OCA\Portaliq\Service\ExampleResident\ExampleResidentInstaller;
use OCA\Portaliq\Service\ExampleResident\ExampleResidentObjects;
use OCA\Portaliq\Service\ExampleResident\ExampleResidentRecord;
use OCA\Portaliq\Service\ExampleResident\ExampleResidentRemover;
use OCA\Portaliq\Service\ExampleResident\ExampleResidentStore;
use OCA\Portaliq\Service\ExampleResident\ExampleResidentUser;
use OCA\Portaliq\Service\ExampleResident\ExampleResidentValues;
use OCA\Portaliq\Service\ExampleResident\ExampleResidentWayIn;
use OCA\Portaliq\Service\ExampleSite\ExampleSiteProof;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The installer runs here against the SHIPPED Zuiddrecht resident and a store
 * that behaves like OpenRegister where it matters: a write is answered with
 * an id whatever happens to the row, a key the schema does not know is not
 * kept, a case gets its number from the register, a reference is not a
 * property the register filters on, and a case cannot be deleted.
 */
class ExampleResidentInstallerTest extends TestCase {
	/**
	 * The day every test installs on.
	 */
	private const TODAY = '2026-10-05T12:00:00+02:00';

	/**
	 * The rows the fake store holds, per "register schema", by id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * Top-level keys the fake store does not keep, per "register schema".
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $unknownKeys = [];

	/**
	 * The "register schema" pairs this instance does not have.
	 *
	 * @var array<int, string>
	 */
	private array $absent = [];

	/**
	 * The "register schema" pairs whose rows cannot be deleted.
	 *
	 * @var array<int, string>
	 */
	private array $undeletable = [];

	/**
	 * The Nextcloud accounts, display name by id.
	 *
	 * @var array<string, string>
	 */
	private array $users = [];

	/**
	 * The fake app config.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * The remover over the same fakes as the last installer.
	 *
	 * @var ExampleResidentRemover
	 */
	private ExampleResidentRemover $remover;

	/**
	 * Start every test on an instance with the example site, dossiq's case types and nobody called Sanne.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->rows = [
			'portaliq portal'        => [
				'portal-1' => [
					'id'             => 'portal-1',
					'slug'           => 'zuiddrecht',
					'title'          => 'Gemeente Zuiddrecht',
					'authentication' => [
						'modes'      => ['public', 'digid', 'eherkenning'],
						'modeLabels' => ['digid' => ['title' => 'Als inwoner']],
					],
				],
			],
			'portaliq portalAccount' => [],
			'dossiq caseType'        => [],
			'dossiq statusType'      => [],
			'dossiq case'            => [],
			'dossiq aanvullingsverzoek' => [],
			'dossiq portaalBericht'  => [],
		];
		$types = [
			'woo-verzoek'             => ['Ontvangst', 'Beoordeling ontvankelijkheid', 'Besluit', 'Afgehandeld'],
			'omgevingsvergunning'     => ['Ontvangen', 'In behandeling', 'Besluitvorming', 'Afgehandeld'],
			'melding-openbare-ruimte' => ['Ontvangen', 'In behandeling', 'Afgehandeld'],
			'subsidieaanvraag'        => ['Ontvangen', 'Beoordeling', 'Besluitvorming', 'Afgehandeld'],
		];
		foreach ($types as $identifier => $statuses) {
			$this->rows['dossiq caseType']['ct-' . $identifier] = ['id' => 'ct-' . $identifier, 'identifier' => $identifier];
			foreach ($statuses as $name) {
				$id = 'st-' . $identifier . '-' . $name;
				$this->rows['dossiq statusType'][$id] = ['id' => $id, 'caseType' => 'ct-' . $identifier, 'name' => $name];
			}
		}

		$this->unknownKeys = [];
		$this->absent = [];
		$this->undeletable = [];
		$this->users = [];
		$this->config = [];
	}//end setUp()

	/**
	 * A fresh instance gets the account, the way in and everything the declaration lists.
	 *
	 * @return void
	 */
	public function testAFreshInstanceGetsTheWholeResident(): void {
		$report = $this->installer()->install(resident: $this->zuiddrecht(), today: new DateTimeImmutable(self::TODAY));

		$this->assertTrue($report['ok'], implode("\n", array_merge($report['dropped'], $report['missing'], $report['lost'])));
		$this->assertSame('', $report['stopped']);
		$this->assertSame('created', $report['user']);
		$this->assertSame('created', $report['account']);
		$this->assertSame('added', $report['signIn']);
		$this->assertNotSame('', $report['password']);
		$this->assertSame(['declared' => 5, 'created' => 5, 'kept' => 0, 'arrived' => 5], $report['types']['dossiq case']);
		$this->assertSame(['declared' => 1, 'created' => 1, 'kept' => 0, 'arrived' => 1], $report['types']['dossiq aanvullingsverzoek']);
		$this->assertSame(['declared' => 4, 'created' => 4, 'kept' => 0, 'arrived' => 4], $report['types']['dossiq portaalBericht']);

		// The Nextcloud account, and a portal account under the same id.
		$this->assertSame(['sanne.devries' => 'Sanne de Vries'], $this->users);
		$account = array_values($this->rows['portaliq portalAccount'])[0];
		$this->assertSame('sanne.devries', $account['subjectRef']);
		$this->assertSame('citizen', $account['audience']);
		$this->assertSame('active', $account['status']);
		$this->assertArrayNotHasKey('email', $account);

		// The portal offers the mode, with its card, and kept what it had.
		$auth = $this->rows['portaliq portal']['portal-1']['authentication'];
		$this->assertSame(['public', 'digid', 'eherkenning', 'nextcloud'], $auth['modes']);
		$this->assertSame('Inloggen als voorbeeldinwoner', $auth['modeLabels']['nextcloud']['button']);
		$this->assertSame('Als inwoner', $auth['modeLabels']['digid']['title']);

		// Every case is the resident's, and nothing is left to fill in.
		foreach ($this->rows['dossiq case'] as $case) {
			$this->assertSame('sanne.devries', $case['portalSubject']);
			$this->assertStringStartsWith('ct-', $case['caseType']);
			$this->assertStringStartsWith('st-', $case['status']);
			$this->assertIsString($case['statusHistory']);
		}

		$this->assertStringNotContainsString('{{', (string)json_encode($this->rows));

		// Dates count from the day of the install, in the declaration's time zone.
		$woo = $this->caseTitled(title: 'Verlichting fietspad Lindelaan');
		$this->assertSame('2026-10-03', $woo['startDate']);
		$this->assertSame('2026-10-03T09:12:00+02:00', $woo['receivedAt']);
		$this->assertSame('st-woo-verzoek-Beoordeling ontvankelijkheid', $woo['status']);

		// The question points at its case and makes the case wait for the resident.
		$dakkapel = $this->caseTitled(title: 'Dakkapel Lindelaan 14');
		$question = array_values($this->rows['dossiq aanvullingsverzoek'])[0];
		$this->assertSame($dakkapel['id'], $question['case']);
		$this->assertSame('2026-10-18', $question['hersteltermijn']);
		$this->assertTrue($dakkapel['waitingOnApplicant']);

		// A message quotes the number the register gave the case.
		$quoted = array_filter(
			$this->rows['dossiq portaalBericht'],
			static fn (array $message): bool => str_contains($message['content'], $woo['identifier']) === true
		);
		$this->assertCount(1, $quoted);
	}//end testAFreshInstanceGetsTheWholeResident()

	/**
	 * A second run writes nothing, makes no second account and shows no password.
	 *
	 * @return void
	 */
	public function testASecondRunChangesNothing(): void {
		$installer = $this->installer();
		$installer->install(resident: $this->zuiddrecht(), today: new DateTimeImmutable(self::TODAY));
		$before = $this->rows;

		$report = $installer->install(resident: $this->zuiddrecht(), today: new DateTimeImmutable('2026-11-20T09:00:00+01:00'));

		$this->assertSame($before, $this->rows);
		$this->assertTrue($report['ok']);
		$this->assertSame('kept', $report['user']);
		$this->assertSame('kept', $report['account']);
		$this->assertSame('kept', $report['signIn']);
		$this->assertSame('', $report['password']);
		$this->assertSame(['declared' => 5, 'created' => 0, 'kept' => 5, 'arrived' => 5], $report['types']['dossiq case']);
	}//end testASecondRunChangesNothing()

	/**
	 * Without the example site nothing is written, not even the account.
	 *
	 * @return void
	 */
	public function testNothingIsWrittenWithoutTheExampleSite(): void {
		$this->rows['portaliq portal'] = [];

		$report = $this->installer()->install(resident: $this->zuiddrecht());

		$this->assertFalse($report['ok']);
		$this->assertStringContainsString('occ portaliq:example-site:install zuiddrecht', $report['stopped']);
		$this->assertSame([], $this->users);
		$this->assertSame([], $this->rows['portaliq portalAccount']);
		$this->assertSame([], $this->config);
	}//end testNothingIsWrittenWithoutTheExampleSite()

	/**
	 * A Nextcloud account that is somebody's is never taken over.
	 *
	 * @return void
	 */
	public function testAnExistingNextcloudAccountIsLeftAlone(): void {
		$this->users = ['sanne.devries' => 'Een echte Sanne'];

		$report = $this->installer()->install(resident: $this->zuiddrecht());

		$this->assertStringContainsString('--user', $report['stopped']);
		$this->assertSame(['sanne.devries' => 'Een echte Sanne'], $this->users);
		$this->assertSame([], $this->rows['portaliq portalAccount']);
		$this->assertSame([], $this->rows['dossiq case']);
		$this->assertSame(['public', 'digid', 'eherkenning'], $this->rows['portaliq portal']['portal-1']['authentication']['modes']);

		// Another id works, and every case is written for that id.
		$report = $this->installer()->install(resident: $this->zuiddrecht(), userId: 'demo-inwoner', today: new DateTimeImmutable(self::TODAY));

		$this->assertTrue($report['ok'], implode("\n", array_merge($report['dropped'], $report['missing'], $report['lost'])));
		$this->assertSame('demo-inwoner', $report['userId']);
		$this->assertSame('demo-inwoner', $this->caseTitled(title: 'Dakkapel Lindelaan 14')['portalSubject']);
	}//end testAnExistingNextcloudAccountIsLeftAlone()

	/**
	 * A portal account that is somebody's is never taken over either.
	 *
	 * @return void
	 */
	public function testAnExistingPortalAccountIsLeftAlone(): void {
		$this->rows['portaliq portalAccount']['real'] = ['id' => 'real', 'subjectRef' => 'sanne.devries'];

		$report = $this->installer()->install(resident: $this->zuiddrecht());

		$this->assertStringContainsString('portal account', $report['stopped']);
		$this->assertSame([], $this->users);
	}//end testAnExistingPortalAccountIsLeftAlone()

	/**
	 * Without dossiq the resident can sign in, and every object that could not be written is named.
	 *
	 * @return void
	 */
	public function testWithoutTheCaseAppEveryObjectIsNamed(): void {
		$this->absent = ['dossiq case', 'dossiq aanvullingsverzoek', 'dossiq portaalBericht'];

		$report = $this->installer()->install(resident: $this->zuiddrecht());

		$this->assertFalse($report['ok']);
		$this->assertSame('created', $report['account']);
		$this->assertCount(10, $report['dropped']);
		$this->assertContains('dossiq case woo-fietspad: this instance has no schema "case" in a register "dossiq"', $report['dropped']);
		$this->assertSame(0, $report['types']['dossiq case']['created']);
	}//end testWithoutTheCaseAppEveryObjectIsNamed()

	/**
	 * A case type this instance lacks: the case is named with what was looked
	 * for, and so is the message that is about it.
	 *
	 * @return void
	 */
	public function testAMissingCaseTypeIsNamedAndSoIsWhatNeedsIt(): void {
		unset($this->rows['dossiq caseType']['ct-woo-verzoek']);

		$report = $this->installer()->install(resident: $this->zuiddrecht());

		$this->assertFalse($report['ok']);
		$this->assertContains('dossiq case woo-fietspad: no caseType with identifier "woo-verzoek" on this instance', $report['dropped']);
		$this->assertCount(2, $report['dropped']);
		$this->assertStringStartsWith('dossiq portaalBericht bericht-woo-ontvangen: it needs {{object:woo-fietspad.id}}', $report['dropped'][1]);
		$this->assertSame(4, $report['types']['dossiq case']['created']);
		$this->assertSame(3, $report['types']['dossiq portaalBericht']['arrived']);
	}//end testAMissingCaseTypeIsNamedAndSoIsWhatNeedsIt()

	/**
	 * A register that does not keep a key: the write is answered, and the report names the key.
	 *
	 * @return void
	 */
	public function testAKeyTheRegisterDropsIsNamed(): void {
		$this->unknownKeys = ['dossiq case' => ['waitingOnApplicant', 'statusHistory'], 'portaliq portalAccount' => ['displayName']];

		$report = $this->installer()->install(resident: $this->zuiddrecht());

		$this->assertFalse($report['ok']);
		$this->assertSame(5, $report['types']['dossiq case']['arrived']);
		$this->assertContains('dossiq case vergunning-dakkapel: waitingOnApplicant', $report['lost']);
		$this->assertContains('dossiq case woo-fietspad: statusHistory', $report['lost']);
		$this->assertContains('portal account sanne.devries: displayName', $report['lost']);
		$this->assertSame([], $report['missing']);
	}//end testAKeyTheRegisterDropsIsNamed()

	/**
	 * Removing takes away what the install made and leaves the portal as it was.
	 *
	 * @return void
	 */
	public function testRemoveDeletesWhatTheInstallMade(): void {
		$before = $this->rows;
		$this->installer()->install(resident: $this->zuiddrecht());

		$report = $this->remover->remove(id: 'zuiddrecht', slug: 'zuiddrecht');

		$this->assertTrue($report['recorded']);
		$this->assertSame([], $report['failed']);
		$this->assertSame(['deleted' => 5, 'gone' => 0], $report['types']['dossiq case']);
		$this->assertSame('deleted', $report['account']);
		$this->assertSame('withdrawn', $report['signIn']);
		$this->assertSame('deleted', $report['user']);
		$this->assertSame($before, $this->rows);
		$this->assertSame([], $this->users);
		$this->assertSame([], $this->config);

		// Nothing is recorded any more, so a second removal does nothing.
		$this->assertFalse($this->remover->remove(id: 'zuiddrecht', slug: 'zuiddrecht')['recorded']);
	}//end testRemoveDeletesWhatTheInstallMade()

	/**
	 * A case OpenRegister will not delete is named and stays recorded, and a
	 * new install for the same id uses it again instead of writing a second.
	 *
	 * @return void
	 */
	public function testACaseThatCannotBeDeletedStaysRecordedAndIsUsedAgain(): void {
		$this->undeletable = ['dossiq case'];
		$installer = $this->installer();
		$installer->install(resident: $this->zuiddrecht());

		$report = $this->remover->remove(id: 'zuiddrecht', slug: 'zuiddrecht');

		$this->assertCount(5, $report['failed']);
		$this->assertContains('dossiq case woo-fietspad', $report['failed']);
		$this->assertSame(['deleted' => 4, 'gone' => 0], $report['types']['dossiq portaalBericht']);
		$this->assertSame('deleted', $report['user']);
		$this->assertCount(5, $this->rows['dossiq case']);

		$again = $installer->install(resident: $this->zuiddrecht());

		$this->assertTrue($again['ok'], implode("\n", array_merge($again['dropped'], $again['missing'], $again['lost'])));
		$this->assertSame(['declared' => 5, 'created' => 0, 'kept' => 5, 'arrived' => 5], $again['types']['dossiq case']);
		$this->assertSame('created', $again['user']);
		$this->assertCount(5, $this->rows['dossiq case']);
	}//end testACaseThatCannotBeDeletedStaysRecordedAndIsUsedAgain()

	/**
	 * A mode the portal offered before the install, and an account the
	 * install did not make, both stay when the resident is removed.
	 *
	 * @return void
	 */
	public function testRemoveLeavesWhatWasThereBefore(): void {
		$this->rows['portaliq portal']['portal-1']['authentication']['modes'][] = 'nextcloud';
		$this->installer()->install(resident: $this->zuiddrecht());

		$report = $this->remover->remove(id: 'zuiddrecht', slug: 'zuiddrecht');

		$this->assertSame('kept-not-ours', $report['signIn']);
		$this->assertContains('nextcloud', $this->rows['portaliq portal']['portal-1']['authentication']['modes']);
	}//end testRemoveLeavesWhatWasThereBefore()

	/**
	 * The values: every form is filled, and a form that cannot be filled is named.
	 *
	 * @return void
	 */
	public function testValuesFillEveryFormAndNameAGap(): void {
		$gaps = [];
		$context = [
			'subject' => 'sanne',
			'lookups' => ['status' => 'st-1'],
			'objects' => ['zaak' => ['id' => 'c-1', 'identifier' => '2026-0001']],
			'today'   => new DateTimeImmutable('2026-03-01 15:00', new DateTimeZone('Europe/Amsterdam')),
		];
		$filled = (new ExampleResidentValues())->fill(
			value: [
				'a' => '{{subject}}',
				'b' => ['{{lookup:status}}', 'Zaak {{object:zaak.identifier}} van {{subject}}'],
				'c' => '{{date:-2}}',
				'd' => '{{datetime:30 08:05}}',
				'e' => true,
				'f' => '{{object:weg.id}}',
				'g' => '{{datetime:1 25:00}}',
				'h' => '{{iets}}',
			],
			context: $context,
			gaps: $gaps
		);

		$this->assertSame('sanne', $filled['a']);
		$this->assertSame(['st-1', 'Zaak 2026-0001 van sanne'], $filled['b']);
		$this->assertSame('2026-02-27', $filled['c']);
		// Past the change to summer time: the offset is the day's own.
		$this->assertSame('2026-03-31T08:05:00+02:00', $filled['d']);
		$this->assertTrue($filled['e']);
		$this->assertSame(['{{object:weg.id}}', '{{datetime:1 25:00}}', '{{iets}}'], $gaps);
	}//end testValuesFillEveryFormAndNameAGap()

	/**
	 * The catalogue hands out the shipped resident and refuses a file that is not one.
	 *
	 * @return void
	 */
	public function testCatalogueKnowsTheShippedResident(): void {
		$catalogue = new ExampleResidentCatalogue();

		$this->assertSame(['zuiddrecht'], $catalogue->ids());
		$this->assertNull($catalogue->find(id: 'nergens'));
		$this->assertNull($catalogue->find(id: '../zuiddrecht'));

		$folder = sys_get_temp_dir() . '/portaliq-residents-' . bin2hex(random_bytes(4));
		mkdir($folder);
		$usable = $this->zuiddrecht();
		$usable['id'] = 'goed';
		file_put_contents($folder . '/goed.json', json_encode($usable));
		file_put_contents($folder . '/andere-naam.json', json_encode($usable));
		$twice = $usable;
		$twice['id'] = 'dubbel';
		$twice['objects'][1]['key'] = $twice['objects'][0]['key'];
		file_put_contents($folder . '/dubbel.json', json_encode($twice));
		file_put_contents($folder . '/kapot.json', '{');

		$this->assertSame(['goed'], (new ExampleResidentCatalogue($folder))->ids());

		array_map('unlink', (array)glob($folder . '/*.json'));
		rmdir($folder);
	}//end testCatalogueKnowsTheShippedResident()

	/**
	 * The shipped declaration.
	 *
	 * @return array<string, mixed>
	 */
	private function zuiddrecht(): array {
		$resident = (new ExampleResidentCatalogue())->find(id: 'zuiddrecht');
		$this->assertNotNull($resident);

		return $resident;
	}//end zuiddrecht()

	/**
	 * The stored case with this title.
	 *
	 * @param string $title The title.
	 *
	 * @return array<string, mixed>
	 */
	private function caseTitled(string $title): array {
		foreach ($this->rows['dossiq case'] as $case) {
			if ($case['title'] === $title) {
				return $case;
			}
		}

		$this->fail('No case "' . $title . '"');
	}//end caseTitled()

	/**
	 * An installer, and a remover, over the fake store, accounts and app config.
	 *
	 * @return ExampleResidentInstaller
	 */
	private function installer(): ExampleResidentInstaller {
		$store = $this->getMockBuilder(ExampleResidentStore::class)
			->disableOriginalConstructor()
			->onlyMethods(['available', 'offers', 'find', 'get', 'create', 'update', 'delete'])
			->getMock();
		$store->method('available')->willReturn(true);
		$store->method('offers')->willReturnCallback(
			fn (string $register, string $schema): bool => isset($this->rows[$register . ' ' . $schema]) === true
				&& in_array($register . ' ' . $schema, $this->absent, true) === false
		);
		$store->method('find')->willReturnCallback(
			function (string $register, string $schema, array $filters): array {
				// Like OpenRegister: a filter on a reference to another object answers with nothing.
				if (isset($filters['caseType']) === true) {
					return [];
				}

				return array_values(
					array_filter(
						($this->rows[$register . ' ' . $schema] ?? []),
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		);
		$store->method('get')->willReturnCallback(
			function (string $register, string $schema, string $id): ?array {
				$row = ($this->rows[$register . ' ' . $schema][$id] ?? null);
				if ($row !== null && is_string($row['statusHistory'] ?? null) === true) {
					// Like OpenRegister: JSON text comes back as the list it holds.
					$row['statusHistory'] = json_decode($row['statusHistory'], true);
				}

				return $row;
			}
		);
		$store->method('create')->willReturnCallback(
			function (string $register, string $schema, array $data): ?string {
				$type = $register . ' ' . $schema;
				$id = $schema . '-' . (count($this->rows[$type]) + 1) . '-' . bin2hex(random_bytes(3));
				foreach (($this->unknownKeys[$type] ?? []) as $key) {
					unset($data[$key]);
				}

				if ($schema === 'case') {
					$data['identifier'] = '2026-' . str_pad((string)(count($this->rows[$type]) + 1), 4, '0', STR_PAD_LEFT);
				}

				$this->rows[$type][$id] = (['id' => $id] + $data);

				return $id;
			}
		);
		$store->method('update')->willReturnCallback(
			function (string $register, string $schema, string $id, array $changes): bool {
				$type = $register . ' ' . $schema;
				if (isset($this->rows[$type][$id]) === false) {
					return false;
				}

				$this->rows[$type][$id] = array_merge($this->rows[$type][$id], $changes);

				return true;
			}
		);
		$store->method('delete')->willReturnCallback(
			function (string $register, string $schema, string $id): bool {
				$type = $register . ' ' . $schema;
				if (isset($this->rows[$type][$id]) === false || in_array($type, $this->undeletable, true) === true) {
					return false;
				}

				unset($this->rows[$type][$id]);

				return true;
			}
		);

		$user = $this->getMockBuilder(ExampleResidentUser::class)
			->disableOriginalConstructor()
			->onlyMethods(['exists', 'newPassword', 'create', 'delete'])
			->getMock();
		$user->method('exists')->willReturnCallback(fn (string $userId): bool => isset($this->users[$userId]));
		$user->method('newPassword')->willReturn('made-up-password');
		$user->method('create')->willReturnCallback(
			function (string $userId, string $displayName, string $password): string {
				$this->users[$userId] = $displayName;

				return '';
			}
		);
		$user->method('delete')->willReturnCallback(
			function (string $userId): bool {
				unset($this->users[$userId]);

				return true;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;

				return true;
			}
		);
		$appConfig->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				unset($this->config[$key]);
			}
		);

		$wayIn = new ExampleResidentWayIn($store);
		$record = new ExampleResidentRecord($appConfig);
		$this->remover = new ExampleResidentRemover($store, $user, $wayIn, $record);

		return new ExampleResidentInstaller(
			$store,
			$user,
			$wayIn,
			new ExampleResidentObjects($store, new ExampleResidentValues()),
			$record,
			new ExampleSiteProof()
		);
	}//end installer()
}//end class

<?php

/**
 * Portaliq Portal Action Service Test
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Actions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/personal-action-list/tasks.md#t02
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Actions;

use OCA\Portaliq\Contribution\CitizenDocumentUpload;
use OCA\Portaliq\Service\Actions\ActionHistory;
use OCA\Portaliq\Service\Actions\PortalActionService;
use OCA\Portaliq\Service\Identity\PortalAccountLookup;
use OCA\Portaliq\Service\Identity\PortalContactService;
use OCA\Portaliq\Service\Plans\PortalPlanService;
use OCA\Portaliq\Service\PortalFileReader;
use OCA\Portaliq\Service\PortalFileWriter;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * REQ-RAL-001 and REQ-RAL-002 over an in-memory store that scopes a read
 * and a write the way the portal's reader and writer do.
 *
 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md
 */
class PortalActionServiceTest extends TestCase {

	/**
	 * The rows by schema, then id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $rows = ['portalPlan' => [], 'portalAction' => [], 'portalPlanTemplate' => []];

	private int $seq = 0;

	/**
	 * The audit rows OpenRegister would hold, by object.
	 *
	 * @var array<string, array<int, array{created: string, changed: array}>>
	 */
	private array $trail = [];

	/**
	 * Files attached, by name.
	 *
	 * @var array<string, string>
	 */
	private array $attached = [];

	private static function scoped(mixed $stored, string $value): bool {
		if (is_array($stored) === true) {
			return in_array($value, $stored, true);
		}

		return $stored === $value && $value !== '';
	}

	/**
	 * The services over the store.
	 *
	 * @return array{0: PortalActionService, 1: PortalPlanService}
	 */
	private function services(): array {
		$reader = $this->createMock(PortalObjectReader::class);
		$read   = fn (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation='', int $limit=200): array => array_values(
			array_filter($this->rows[$schema], static fn (array $row): bool => self::scoped($row[$scopeField] ?? null, $subjectRef))
		);
		$reader->method('readCollection')->willReturnCallback($read);
		$reader->method('readCollectionByAnyScope')->willReturnCallback(
			static function (string $register, string $schema, array $scopeFields, string $subjectRef) use ($read): array {
				$rows = [];
				foreach ($scopeFields as $field) {
					foreach ($read($register, $schema, $field, $subjectRef) as $row) {
						$rows[$row['id']] = $row;
					}
				}

				return array_values($rows);
			}
		);
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('createObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data): array {
				$id  = 'o'.(++$this->seq);
				$row = ([$scopeField => $subjectRef] + $data + ['id' => $id, 'organisation' => $organisation]);
				$this->audit(id: $id, old: [], new: $row);
				return ($this->rows[$schema][$id] = $row);
			}
		);
		$writer->method('updateObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, $data): ?array {
				$existing = ($this->rows[$schema][$id] ?? null);
				if ($existing === null || self::scoped($existing[$scopeField] ?? null, $subjectRef) === false) {
					return null;
				}

				$merged = array_merge($existing, $data);
				if (is_array($existing[$scopeField]) === false) {
					$merged[$scopeField] = $subjectRef;
				}

				$this->audit(id: $id, old: $existing, new: $merged);
				return ($this->rows[$schema][$id] = $merged);
			}
		);
		$writer->method('deleteObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id): bool {
				$existing = ($this->rows[$schema][$id] ?? null);
				if ($existing === null || self::scoped($existing[$scopeField] ?? null, $subjectRef) === false) {
					return false;
				}

				unset($this->rows[$schema][$id]);
				return true;
			}
		);
		$contacts = $this->createMock(PortalContactService::class);
		$contacts->method('approvedContacts')->willReturnCallback(
			static fn (string $owner): array => ($owner === 'sanne') ? [['id' => 'c-mark', 'ref' => 'mark', 'displayName' => 'Mark Jansen']] : []
		);
		$accounts = $this->createMock(PortalAccountLookup::class);
		$accounts->method('bySubjectRef')->willReturnCallback(static fn (string $ref): array => ['displayName' => ['sanne' => 'Sanne de Vries', 'mark' => 'Mark Jansen', 'eva' => 'Eva'][$ref] ?? $ref]);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$plans = new PortalPlanService($reader, $writer, $contacts, $accounts, $l10n);

		$fileWriter = $this->createMock(PortalFileWriter::class);
		$fileWriter->method('attachFile')->willReturnCallback(
			function (string $register, string $schema, string $id, string $fileName, string $content): array {
				$this->attached[$fileName] = $id;
				return ['id' => 'f-'.count($this->attached), 'name' => $fileName];
			}
		);
		$fileReader = $this->createMock(PortalFileReader::class);
		$fileReader->method('listFiles')->willReturn([]);

		$mapper = new class($this) {
			public function __construct(private readonly PortalActionServiceTest $test) {
			}

			/**
			 * @return array<int, array{created: string, changed: array}>
			 */
			public function findChangesForObject(string $uuid, int $limit = 1000): array {
				return $this->test->trailOf($uuid);
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);
		$history = new ActionHistory($container, $this->createMock(LoggerInterface::class));

		return [new PortalActionService($reader, $writer, $contacts, $accounts, $plans, $fileReader, $fileWriter, new CitizenDocumentUpload(), $history), $plans];
	}

	/**
	 * The trail of one object, as the mapper answers.
	 *
	 * @param string $uuid The object.
	 *
	 * @return array<int, array{created: string, changed: array}>
	 */
	public function trailOf(string $uuid): array {
		return ($this->trail[$uuid] ?? []);
	}

	private function audit(string $id, array $old, array $new): void {
		$changed = [];
		foreach ($new as $key => $value) {
			if (array_key_exists($key, $old) === false || $old[$key] !== $value) {
				$changed[$key] = ['old' => ($old[$key] ?? null), 'new' => $value];
			}
		}

		$this->trail[$id][] = ['created' => '2026-10-06 14:0'.count($this->trail[$id] ?? []), 'changed' => $changed];
	}

	private function as(string $ref): array {
		return ['subjectRef' => $ref, 'organisation' => 'org-1'];
	}

	/**
	 * Sanne adds "Bankafschriften uploaden", due 14 October: listed as Te doen with its date.
	 */
	public function testAddingAnAction(): void {
		[$actions] = $this->services();
		$made = $actions->create($this->as('sanne'), ['title' => 'Bankafschriften van drie maanden uploaden', 'endDate' => '2026-10-14']);
		$this->assertSame('ok', $made['status']);

		$list = $actions->overview($this->as('sanne'));
		$this->assertSame(1, $list['total']);
		$this->assertSame(0, $list['done']);
		$this->assertSame('todo', $list['actions'][0]['status']);
		$this->assertSame('2026-10-14', $list['actions'][0]['endDate']);
		$this->assertSame('owner', $list['actions'][0]['role']);
		$this->assertSame(['sanne', 'mark'], array_column($list['assignees'], 'ref'));
		$this->assertSame('sanne', $this->rows['portalAction'][$made['id']]['editedBy']);
	}

	/**
	 * An action can go to an approved contact only, and the contact sees only that one.
	 */
	public function testSharedWithAnApprovedContactOnly(): void {
		[$actions] = $this->services();
		$this->assertSame('invalid', $actions->create($this->as('sanne'), ['title' => 'X', 'assignee' => 'eva'])['status']);
		$shared = $actions->create($this->as('sanne'), ['title' => 'Aanvraag schuldhulpverlening indienen', 'assignee' => 'mark'])['id'];
		$actions->create($this->as('sanne'), ['title' => 'Alleen van Sanne']);

		$this->assertSame([$shared], array_column($actions->overview($this->as('mark'))['actions'], 'id'));
		$this->assertSame([], $actions->overview($this->as('eva'))['actions']);
		$this->assertSame('not_found', $actions->update($this->as('eva'), $shared, ['status' => 'done']));
	}

	/**
	 * Mark sets the action to Bezig, and Sanne's history names him; he cannot delete or reassign it.
	 */
	public function testTheAssigneeChangesStatusButCannotDeleteOrReassign(): void {
		[$actions] = $this->services();
		$id = $actions->create($this->as('sanne'), ['title' => 'Aanvraag schuldhulpverlening indienen', 'assignee' => 'mark', 'endDate' => '2026-10-14'])['id'];

		$this->assertSame('ok', $actions->update($this->as('mark'), $id, ['status' => 'doing']));
		$this->assertSame('forbidden', $actions->update($this->as('mark'), $id, ['assignee' => 'sanne']));
		$this->assertSame('forbidden', $actions->update($this->as('mark'), $id, ['title' => 'Anders']));
		$this->assertSame('forbidden', $actions->delete($this->as('mark'), $id));
		$this->assertSame('mark', $this->rows['portalAction'][$id]['assignee']);

		$history = $actions->history($this->as('sanne'), $id);
		$this->assertSame(['kind' => 'status', 'field' => 'status', 'from' => 'todo', 'to' => 'doing'], array_intersect_key($history[0], array_flip(['kind', 'field', 'from', 'to'])));
		$this->assertSame('Mark Jansen', $history[0]['who']);
		$this->assertSame(['created', 'Sanne de Vries', '2026-10-14'], [$history[1]['kind'], $history[1]['who'], $history[1]['to']]);
		$this->assertNull($actions->history($this->as('eva'), $id));

		$this->assertSame('ok', $actions->delete($this->as('sanne'), $id));
	}

	/**
	 * One file per action: a 12 MB PDF and a renamed file are refused, a PDF is kept and can be taken off.
	 */
	public function testOneFileOfTheRightKind(): void {
		[$actions] = $this->services();
		$id = $actions->create($this->as('sanne'), ['title' => 'Bankafschriften uploaden'])['id'];

		$this->assertSame('invalid', $actions->attach($this->as('sanne'), $id, $this->upload('groot.pdf', '%PDF-'.str_repeat('x', 12 * 1024 * 1024))));
		$this->assertSame('invalid', $actions->attach($this->as('sanne'), $id, $this->upload('script.pdf', '<?php echo 1;')));
		$this->assertSame('ok', $actions->attach($this->as('sanne'), $id, $this->upload('bankafschrift-juli.pdf', '%PDF-1.7 content')));
		$this->assertSame('bankafschrift-juli.pdf', $this->rows['portalAction'][$id]['fileName']);
		$this->assertSame('file', $actions->history($this->as('sanne'), $id)[0]['kind']);

		$this->assertSame('invalid', $actions->update($this->as('sanne'), $id, ['file' => 'f-99']));
		$this->assertSame('ok', $actions->update($this->as('sanne'), $id, ['file' => '']));
		$this->assertSame('', $this->rows['portalAction'][$id]['fileName']);
	}

	/**
	 * In a plan every participant edits an action and may give it to any participant; the plan's owner deletes it.
	 */
	public function testAnActionInAPlan(): void {
		[$actions, $plans] = $this->services();
		$planId = $plans->start($this->as('sanne'), 'zuid', '', 'Schuldhulp op orde', ['c-mark'], '2026-09-01')['id'];
		$this->assertSame('ok', $plans->addAction($this->as('mark'), $planId, ['title' => 'Budgetcoach bellen']));
		$id = array_key_last($this->rows['portalAction']);
		$this->assertSame('mark', $this->rows['portalAction'][$id]['editedBy'], 'a plan writes who added the action');

		$this->assertSame('ok', $actions->update($this->as('sanne'), $id, ['title' => 'Budgetcoach bellen en afspraak maken', 'assignee' => 'sanne'], $planId));
		$this->assertSame('invalid', $actions->update($this->as('sanne'), $id, ['assignee' => 'eva'], $planId));
		$this->assertSame('not_found', $actions->update($this->as('eva'), $id, ['status' => 'done'], $planId));

		$this->assertSame('sanne', $this->rows['portalAction'][$id]['editedBy']);
		$this->assertSame('ok', $plans->updateAction($this->as('mark'), $planId, $id, ['status' => 'doing']));
		$this->assertSame('Mark Jansen', $actions->history($this->as('sanne'), $id, $planId)[0]['who'], 'a change through the plan names its editor');

		$listed = $actions->overview($this->as('sanne'))['actions'][0];
		$this->assertSame(['member', 'Schuldhulp op orde'], [$listed['role'], $listed['planTitle']]);

		$this->assertSame('ok', $actions->delete($this->as('sanne'), $id, $planId));
	}

	/**
	 * A multipart entry for a file with these bytes.
	 *
	 * @return array<string, mixed>
	 */
	private function upload(string $name, string $content): array {
		$path = tempnam(sys_get_temp_dir(), 'pqa');
		file_put_contents($path, $content);

		return ['name' => $name, 'tmp_name' => $path, 'error' => 0, 'size' => strlen($content)];
	}
}

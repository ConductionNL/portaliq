<?php

/**
 * Portaliq Action Settings Controller
 *
 * `GET` and `PUT /api/settings/actions`: which groups may do which action.
 * Administrator only; there is no opt-out attribute, the same way
 * SettingsController::update() is.
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
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
 * @spec openspec/changes/operate-roles-for-content-and-actions/tasks.md#t04
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use JsonException;
use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\ActionAuthService;
use OCA\Portaliq\Service\PageEditorService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Reads and writes the action-to-groups matrix for the admin settings.
 *
 * @spec openspec/changes/operate-roles-for-content-and-actions/tasks.md#t04
 */
class ActionSettingsController extends Controller {
	private const SEED_PATH = __DIR__ . '/../actions.seed.json';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ActionAuthService $actions Holds the matrix.
	 * @param PageEditorService $groups Lists the groups that exist.
	 * @param IL10N $l The translator for the catalogue's labels.
	 * @param string $seedPath The catalogue file, for tests.
	 * @param IGroupManager|null $groupManager Counts a group's members.
	 */
	public function __construct(
		IRequest $request,
		private readonly ActionAuthService $actions,
		private readonly PageEditorService $groups,
		private readonly IL10N $l,
		private readonly string $seedPath = self::SEED_PATH,
		private readonly ?IGroupManager $groupManager = null,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The catalogue joined with the stored grants, and the groups to pick from.
	 *
	 * @return JSONResponse
	 *
	 * @auth admin-only Decides which groups may act on residents' behalf, so only an instance administrator may see or change it.
	 *
	 * @spec openspec/changes/operate-roles-for-content-and-actions/specs/portal-admin-roles/spec.md#requirement-an-administrator-grants-an-action-to-a-group-on-screen-req-ora-001
	 */
	public function index(): JSONResponse {
		$matrix = $this->actions->getMatrix();
		$rows = [];
		foreach ($this->catalogue() as $action => $entry) {
			$rows[] = [
				'action' => $action,
				'label' => $this->l->t($entry['label']),
				'description' => $this->l->t($entry['description']),
				// `admin` is a display hint; an administrator always passes.
				'groups' => array_values(array_diff(($matrix[$action] ?? $entry['groups']), ['admin'])),
			];
		}

		return new JSONResponse(['actions' => $rows, 'availableGroups' => $this->groups->availableGroups()]);
	}//end index()

	/**
	 * The same grants per group: every group that holds a right, with its
	 * name, how many members it has and the rights it holds. Administrators
	 * hold every right and are not listed.
	 *
	 * @return JSONResponse `{results, total}`.
	 *
	 * @auth admin-only Shows who may act on residents' behalf, so only an
	 *       instance administrator may see it; Nextcloud checks it before this
	 *       method runs.
	 *
	 * @spec openspec/changes/admin-rights-page/specs/portal-admin-roles/spec.md#requirement-an-administrator-reads-the-rights-per-group-req-arp-001
	 */
	public function byGroup(): JSONResponse {
		$names = array_column($this->groups->availableGroups(), 'label', 'id');
		$matrix = $this->actions->getMatrix();
		$held = [];
		foreach ($this->catalogue() as $action => $entry) {
			foreach (array_diff(($matrix[$action] ?? $entry['groups']), ['admin']) as $group) {
				$held[$group][] = $this->l->t($entry['label']);
			}
		}

		$rows = [];
		foreach ($held as $group => $rights) {
			$rows[] = [
				'group' => (string)$group,
				'name' => (string)($names[$group] ?? $group),
				'members' => (int)$this->groupManager?->get((string)$group)?->count(),
				'rights' => implode(', ', $rights),
				'count' => count($rights),
			];
		}

		usort($rows, static fn (array $one, array $two): int => strcasecmp($one['name'], $two['name']));

		return new JSONResponse(['results' => $rows, 'total' => count($rows)]);
	}//end byGroup()

	/**
	 * Store the grants: known actions and existing groups only.
	 *
	 * @param array<string, mixed> $grants Action name to group ids.
	 *
	 * @return JSONResponse
	 *
	 * @auth admin-only Decides which groups may act on residents' behalf, so only an instance administrator may see or change it.
	 *
	 * @spec openspec/changes/operate-roles-for-content-and-actions/specs/portal-admin-roles/spec.md#requirement-the-grants-accept-only-known-actions-and-existing-groups-req-ora-002
	 */
	public function update(array $grants = []): JSONResponse {
		$catalogue = $this->catalogue();
		$existing = array_column($this->groups->availableGroups(), 'id');
		$matrix = $this->actions->getMatrix();

		foreach ($grants as $action => $groups) {
			if (is_string($action) === false || isset($catalogue[$action]) === false || is_array($groups) === false) {
				continue;
			}

			$kept = $this->keptGroups(groups: $groups, existing: $existing);

			// Administrators always pass; an empty list reads "only administrators".
			if ($kept === []) {
				$kept = ['admin'];
			}

			$matrix[$action] = $kept;
		}

		try {
			$this->actions->setMatrix($matrix);
		} catch (JsonException) {
			return new JSONResponse(['saved' => false], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return $this->index();
	}//end update()

	/**
	 * The groups that exist, without duplicates.
	 *
	 * @param array<int|string, mixed> $groups   The declared groups.
	 * @param array<int, mixed>        $existing The ids of the groups that exist.
	 *
	 * @return array<int, string>
	 */
	private function keptGroups(array $groups, array $existing): array {
		$kept = [];
		foreach ($groups as $group) {
			if (is_string($group) === true && in_array($group, $existing, true) === true && in_array($group, $kept, true) === false) {
				$kept[] = $group;
			}
		}

		return $kept;
	}//end keptGroups()

	/**
	 * The seed's catalogue: action to label, description and default groups.
	 *
	 * @return array<string, array{label: string, description: string, groups: array<int, string>}>
	 */
	private function catalogue(): array {
		if (is_file($this->seedPath) === false || is_readable($this->seedPath) === false) {
			return [];
		}

		$raw = file_get_contents($this->seedPath);
		if ($raw === false) {
			return [];
		}

		$parsed = json_decode($raw, true);
		$actions = null;
		if (is_array($parsed) === true) {
			$actions = ($parsed['actions'] ?? null);
		}

		if (is_array($actions) === false) {
			return [];
		}

		$out = [];
		foreach ($actions as $action => $entry) {
			if (is_string($action) === false) {
				continue;
			}

			$out[$action] = $this->catalogueEntry(action: $action, entry: $entry);
		}

		return $out;
	}//end catalogue()

	/**
	 * One catalogue entry: the old bare list form has no label, so the action name stands in.
	 *
	 * @param string $action The action name.
	 * @param mixed  $entry  The seed's entry.
	 *
	 * @return array{label: string, description: string, groups: array<int, string>}
	 */
	private function catalogueEntry(string $action, mixed $entry): array {
		if (is_array($entry) === true && array_key_exists('groups', $entry) === true) {
			return [
				'label' => (string)($entry['label'] ?? $action),
				'description' => (string)($entry['description'] ?? ''),
				'groups' => (array)$entry['groups'],
			];
		}

		return ['label' => $action, 'description' => '', 'groups' => (array)$entry];
	}//end catalogueEntry()
}//end class

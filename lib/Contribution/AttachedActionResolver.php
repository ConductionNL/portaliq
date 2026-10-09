<?php
/**
 * Portaliq Attached Action Resolver (woo-journey-entry-points)
 *
 * Lets an app offer an endpoint action on ANOTHER app's collection. pipelinq's
 * "Stel een vraag over dit dossier" and dossiq's "Start een Woo-verzoek" live
 * in their own apps, and belong on opencatalogi's dossier page. Row actions
 * only resolve within one contribution, so the declaring action names its
 * target itself: `attachTo: {app, schema}`, plus the usual `rowField`.
 * `attachTo.collection` narrows it to that one collection of the target, and
 * the target may be the action's own app: pipelinq's reply belongs on the
 * resident's question detail only, not on every ticket list
 * (attach-to-own-collection).
 *
 * INVARIANT: presentation-only, like RowActionResolver. It never changes the
 * action, and lists on the target collection only what the resident's form
 * needs (id, label, fields and their presentation). The forward proves the row
 * through the TARGET collection's scope and looks the action up again in its
 * own contribution; the receiving app checks ownership itself as well.
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
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
 * @spec openspec/changes/woo-journey-entry-points/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-action-must-be-able-to-attach-to-another-apps-collection-req-wje-004
 * @spec openspec/changes/attach-to-own-collection/specs/portal-contribution-contract/spec.md#requirement-an-attached-action-must-be-able-to-name-one-collection-and-its-own-app-req-ato-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Resolves `attachTo` declarations into `attachedActions` on target collections.
 *
 * @spec openspec/changes/woo-journey-entry-points/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-action-must-be-able-to-attach-to-another-apps-collection-req-wje-004
 */
class AttachedActionResolver {
	/**
	 * The manifest key on the collection.
	 */
	public const KEY = 'attachedActions';

	/**
	 * The action keys the target collection lists; never the endpoint.
	 * `rowWhen` is listed so a renderer can leave the action off a row it does
	 * not apply to; the forward checks it again on the row it read.
	 */
	private const LISTED = ['label', 'fields', 'fieldConfigs', 'submitLabel', 'successMessage', 'rowWhen', 'steps', 'confirmation', 'draft', 'summary', 'answerSummary'];

	/**
	 * Add each attaching action to the collections it names.
	 *
	 * A list a provider put on its own collection is removed first: only this
	 * resolver writes the key.
	 *
	 * @param array<int, array<string, mixed>> $contributions The normalised contributions.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/woo-journey-entry-points/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-action-must-be-able-to-attach-to-another-apps-collection-req-wje-004
	 */
	public function resolve(array $contributions): array {
		$attaching = $this->attaching(contributions: $contributions);

		foreach ($contributions as $index => $contribution) {
			if (is_array($contribution) === false) {
				continue;
			}

			$app = (string)($contribution['app'] ?? '');
			foreach ((array)($contribution['collections'] ?? []) as $position => $collection) {
				if (is_array($collection) === false) {
					continue;
				}

				unset($collection[self::KEY]);
				$listed = [];
				foreach ($attaching as $entry) {
					if ($this->targets(entry: $entry, app: $app, collection: $collection) === true) {
						$listed[] = $entry['listed'];
					}
				}

				if ($listed !== []) {
					$collection[self::KEY] = $listed;
				}

				$contributions[$index]['collections'][$position] = $collection;
			}
		}//end foreach

		return $contributions;
	}//end resolve()

	/**
	 * The attaching action a forward names, when the collection lists it and
	 * its own app still offers it; else null.
	 *
	 * @param array<int, array<string, mixed>> $contributions The resolved contributions.
	 * @param string                           $collectionApp The app that owns the collection.
	 * @param array<string, mixed>             $collection    The target collection.
	 * @param string                           $actionApp     The app of the action.
	 * @param string                           $actionId      The action id.
	 *
	 * @return array<string, mixed>|null The action as its own app declares it.
	 *
	 * @spec openspec/changes/woo-journey-entry-points/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-action-must-be-able-to-attach-to-another-apps-collection-req-wje-004
	 * @spec openspec/changes/attach-to-own-collection/specs/portal-contribution-contract/spec.md#requirement-an-attached-action-must-be-able-to-name-one-collection-and-its-own-app-req-ato-001
	 */
	public function attachedAction(array $contributions, string $collectionApp, array $collection, string $actionApp, string $actionId): ?array {
		if ($this->isListed(collection: $collection, actionApp: $actionApp, actionId: $actionId) === false) {
			return null;
		}

		foreach ($this->attaching(contributions: $contributions) as $entry) {
			$same = [$entry['app'], $entry['action']['id']] === [$actionApp, $actionId];
			if ($same === true && $this->targets(entry: $entry, app: $collectionApp, collection: $collection) === true) {
				return $entry['action'];
			}
		}

		return null;
	}//end attachedAction()

	/**
	 * Whether the collection lists this app's action.
	 *
	 * @param array<string, mixed> $collection The collection.
	 * @param string               $actionApp  The app of the action.
	 * @param string               $actionId   The action id.
	 *
	 * @return bool
	 */
	private function isListed(array $collection, string $actionApp, string $actionId): bool {
		foreach ((array)($collection[self::KEY] ?? []) as $entry) {
			if (is_array($entry) === true && ($entry['app'] ?? '') === $actionApp && ($entry['id'] ?? '') === $actionId) {
				return true;
			}
		}

		return false;
	}//end isListed()

	/**
	 * Every well-formed attaching action in the aggregate.
	 *
	 * @param array<int, array<string, mixed>> $contributions The contributions.
	 *
	 * @return array<int, array{app: string, target: string, schema: string, collection: string, action: array, listed: array}>
	 */
	private function attaching(array $contributions): array {
		$out = [];
		foreach ($contributions as $contribution) {
			if (is_array($contribution) === false) {
				continue;
			}

			$app = (string)($contribution['app'] ?? '');
			foreach ((array)($contribution['actions'] ?? []) as $action) {
				$entry = $this->attachment(app: $app, action: $action);
				if ($entry !== null) {
					$out[] = $entry;
				}
			}
		}

		return $out;
	}//end attaching()

	/**
	 * One action as an attachment, or null when it does not attach.
	 *
	 * @param string $app    The app that declares it.
	 * @param mixed  $action The action.
	 *
	 * @return array{app: string, target: string, schema: string, collection: string, action: array, listed: array}|null
	 */
	private function attachment(string $app, mixed $action): ?array {
		if (is_array($action) === false || is_string($action['id'] ?? null) === false
			|| (new RowActionResolver())->isEndpointRowAction(action: $action) === false
		) {
			return null;
		}

		$target = ($action['attachTo'] ?? null);
		if (is_array($target) === false || $this->isName(value: ($target['app'] ?? null)) === false
			|| $this->isName(value: ($target['schema'] ?? null)) === false
		) {
			return null;
		}

		// An optional collection id narrows the target to that one collection.
		// A malformed one attaches nothing: widening it to every collection on
		// the schema would show the action where its app never meant it.
		$collection = ($target['collection'] ?? '');
		if ($collection !== '' && $this->isName(value: $collection) === false) {
			return null;
		}

		$listed = $this->listedFor(app: $app, action: $action);

		return [
			'app' => $app,
			'target' => $target['app'],
			'schema' => $target['schema'],
			'collection' => (string)$collection,
			'action' => $action,
			'listed' => $listed,
		];
	}//end attachment()

	/**
	 * Whether an attachment targets this collection of this app: the app and
	 * the schema, and the collection id when the attachment names one.
	 *
	 * @param array{target: string, schema: string, collection: string} $entry      The attachment.
	 * @param string                                                     $app        The app that owns the collection.
	 * @param array<string, mixed>                                       $collection The collection.
	 *
	 * @return bool
	 */
	private function targets(array $entry, string $app, array $collection): bool {
		if ($entry['target'] !== $app || $entry['schema'] !== (string)($collection['schema'] ?? '')) {
			return false;
		}

		return $entry['collection'] === '' || $entry['collection'] === (string)($collection['id'] ?? '');
	}//end targets()

	/**
	 * What the target collection lists of an attaching action: never its
	 * endpoint, and never its row field, which the server stamps.
	 *
	 * @param string               $app    The app that declares it.
	 * @param array<string, mixed> $action The action.
	 *
	 * @return array<string, mixed>
	 */
	private function listedFor(string $app, array $action): array {
		$listed = ['app' => $app, 'id' => $action['id']];
		foreach (self::LISTED as $key) {
			if (array_key_exists($key, $action) === true) {
				$listed[$key] = $action[$key];
			}
		}

		// The row field is stamped by the server with the proven row id, so the
		// resident is never asked for it.
		if (is_array($listed['fields'] ?? null) === true) {
			$listed['fields'] = array_values(array_diff($listed['fields'], [$action['rowField']]));
		}

		return $listed;
	}//end listedFor()

	/**
	 * Whether a value is a plain app or schema name.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isName(mixed $value): bool {
		return is_string($value) === true && preg_match('/^[A-Za-z][A-Za-z0-9_.-]*$/', $value) === 1;
	}//end isName()
}//end class

<?php

/**
 * Portaliq Portal Custom Theme Sets
 *
 * The house styles an administrator made or received in the theme app.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Theme
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
 * @spec openspec/changes/nldesign-theme-integration/tasks.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Theme;

use Psr\Container\ContainerInterface;
use Throwable;

/**
 * The theme app's custom token sets, and whether one may be linked.
 *
 * An administrator builds a house style in the theme app (an upload, or a
 * theme shared from another instance through OpenRegister, which the theme
 * app's `NlDesignThemeShareableConfigType` imports as a custom set). Portaliq
 * reads that list rather than keeping its own: the theme app owns theming.
 *
 * A custom set is input from an administrator or from another instance, so
 * before a portal links it, the file on disk goes through the theme app's own
 * `CustomTokenSetValidator` again (task 4.2): one `:root` block, and every
 * declaration judged by the same rule the upload used. A set that fails is
 * refused by name, never linked, and never silently dropped (task 4.4).
 *
 * @spec openspec/changes/nldesign-theme-integration/tasks.md
 */
class PortalCustomThemeSets {

	/**
	 * The id prefix the theme app gives every custom set.
	 */
	public const ID_PREFIX = 'custom-';

	/**
	 * The theme app's custom-set store, under its current and former name.
	 *
	 * @var string[]
	 */
	private const STORES = [
		'OCA\\Thematiq\\Service\\CustomTokenSetService',
		'OCA\\NLDesign\\Service\\CustomTokenSetService',
	];

	/**
	 * The theme app's declaration validator, under its current and former name.
	 *
	 * @var string[]
	 */
	private const VALIDATORS = [
		'OCA\\Thematiq\\Service\\CustomTokenSetValidator',
		'OCA\\NLDesign\\Service\\CustomTokenSetValidator',
	];


	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the theme app's services by name.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()


	/**
	 * Every custom set the theme app holds, each marked `custom`.
	 *
	 * @return array<int, array<string, mixed>> Entries with a string `id` and `name`.
	 *
	 * @spec openspec/changes/nldesign-theme-integration/tasks.md
	 */
	public function all(): array {
		$store = $this->service(ids: self::STORES, method: 'list');
		if ($store === null) {
			return [];
		}

		$sets = [];
		foreach ($this->listed(store: $store) as $entry) {
			$set = $this->customSet(entry: $entry);
			if ($set !== null) {
				$sets[] = $set;
			}
		}

		return $sets;
	}//end all()


	/**
	 * What the store lists; nothing when it fails.
	 *
	 * @param object $store The theme app's CustomTokenSetService.
	 *
	 * @return array<int|string, mixed>
	 */
	private function listed(object $store): array {
		try {
			return (array)$store->list();
		} catch (Throwable) {
			return [];
		}
	}//end listed()


	/**
	 * One listed entry as a custom set, or null when it is not one.
	 *
	 * @param mixed $entry The listed entry.
	 *
	 * @return array{id: string, name: string, custom: true}|null
	 */
	private function customSet(mixed $entry): ?array {
		if (is_array($entry) === false) {
			return null;
		}

		$id = (string)($entry['id'] ?? '');
		if ($this->isCustomId(id: $id) === false) {
			return null;
		}

		return ['id' => $id, 'name' => (string)($entry['name'] ?? $id), 'custom' => true];
	}//end customSet()


	/**
	 * The shipped sets with the custom sets added after them; a custom set
	 * does not replace a shipped set of the same id.
	 *
	 * @param array<int, array<string, mixed>> $sets The shipped sets.
	 *
	 * @return array<int, array<string, mixed>> The shipped and the custom sets.
	 *
	 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
	 */
	public function mergedInto(array $sets): array {
		$ids = [];
		foreach ($sets as $entry) {
			$ids[(string)$entry['id']] = true;
		}

		foreach ($this->all() as $entry) {
			if (isset($ids[$entry['id']]) === false) {
				$sets[] = $entry;
			}
		}

		return $sets;
	}//end mergedInto()

	/**
	 * Whether an id names a custom set.
	 *
	 * @param string $id The set id.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/nldesign-theme-integration/tasks.md
	 */
	public function isCustomId(string $id): bool {
		return preg_match('/^custom-[a-z0-9][a-z0-9-]{0,56}$/', $id) === 1;
	}//end isCustomId()


	/**
	 * Why a custom set's file may not be linked, or null when it may.
	 *
	 * Fails closed: without the theme app's validator nothing is linked,
	 * because an unchecked file from another instance is exactly what this
	 * guards against.
	 *
	 * @param string $id  The set id.
	 * @param string $css The set's file as it is on disk.
	 *
	 * @return string|null The reason, in the validator's words where it gives one.
	 *
	 * @spec openspec/changes/nldesign-theme-integration/tasks.md
	 */
	public function refusal(string $id, string $css): ?string {
		$validator = $this->service(ids: self::VALIDATORS, method: 'validateDeclarations');
		if ($validator === null) {
			return 'the theme app cannot check this house style';
		}

		try {
			return (new CustomSetJudge(validator: $validator))->verdict(id: $id, css: $css);
		} catch (Throwable) {
			return 'the theme app could not check this house style';
		}
	}//end refusal()


	/**
	 * The first of the named services that is registered and has the method.
	 *
	 * @param string[] $ids    Class names, current name first.
	 * @param string   $method A method it must expose.
	 *
	 * @return object|null
	 */
	private function service(array $ids, string $method): ?object {
		foreach ($ids as $id) {
			$service = $this->registered(id: $id);
			if (is_object($service) === true && method_exists($service, $method) === true) {
				return $service;
			}
		}

		return null;
	}//end service()


	/**
	 * The service registered under one name, or null when none is.
	 *
	 * @param string $id The class name.
	 *
	 * @return mixed
	 */
	private function registered(string $id): mixed {
		try {
			return $this->container->get($id);
		} catch (Throwable) {
			return null;
		}
	}//end registered()
}//end class

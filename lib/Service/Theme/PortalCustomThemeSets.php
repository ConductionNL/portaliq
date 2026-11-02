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
	 * How a character changes the bracket depth.
	 *
	 * @var array<string, int>
	 */
	private const NESTING = ['(' => 1, ')' => -1];

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

		try {
			$listed = (array)$store->list();
		} catch (Throwable) {
			return [];
		}

		$sets = [];
		foreach ($listed as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$id = (string)($entry['id'] ?? '');
			if ($this->isCustomId(id: $id) === false) {
				continue;
			}

			$sets[] = ['id' => $id, 'name' => (string)($entry['name'] ?? $id), 'custom' => true];
		}

		return $sets;
	}//end all()


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
			return $this->judge(validator: $validator, id: $id, css: $css);
		} catch (Throwable) {
			return 'the theme app could not check this house style';
		}
	}//end refusal()


	/**
	 * The validator's verdict on one file: null when it passes.
	 *
	 * @param object $validator The theme app's CustomTokenSetValidator.
	 * @param string $id        The set id.
	 * @param string $css       The file.
	 *
	 * @return string|null The reason.
	 */
	private function judge(object $validator, string $id, string $css): ?string {
		if (method_exists($validator, 'hasDisallowedSelector') === true
			&& $validator->hasDisallowedSelector($css) === true
		) {
			return 'the file holds more than one :root block of tokens';
		}

		$declarations = $this->declarations(css: $css);
		if ($declarations === []) {
			return 'the file declares no tokens';
		}

		if ($validator->validateDeclarations($declarations, substr($id, strlen(self::ID_PREFIX))) !== null) {
			return null;
		}

		$last = null;
		if (method_exists($validator, 'getLastError') === true) {
			$last = $validator->getLastError();
		}

		if (is_array($last) === true && is_string($last['message'] ?? null) === true) {
			return $last['message'];
		}

		return 'a declaration was refused';
	}//end judge()


	/**
	 * Every custom-property declaration in a file, name to value.
	 *
	 * Every name is passed on, also one the validator's name pattern would
	 * skip: its value is judged there all the same.
	 *
	 * @param string $css The file.
	 *
	 * @return array<string, string>
	 */
	private function declarations(string $css): array {
		$body = (string)preg_replace('#/\*.*?\*/#s', '', $css);
		$open = strpos($body, '{');
		$close = strrpos($body, '}');
		if ($open !== false && $close !== false && $close > $open) {
			$body = substr($body, ($open + 1), ($close - $open - 1));
		}

		$declarations = [];
		foreach ($this->statements(body: $body) as $statement) {
			$colon = strpos($statement, ':');
			if ($colon === false) {
				continue;
			}

			$name = trim(substr($statement, 0, $colon));
			if (str_starts_with($name, '--') === true) {
				$declarations[$name] = trim(substr($statement, ($colon + 1)));
			}
		}

		return $declarations;
	}//end declarations()


	/**
	 * A block body split on the semicolons that end a declaration, not the
	 * ones inside `url(...)` or a quoted string (a `data:` logo has one).
	 *
	 * @param string $body The declarations of one block.
	 *
	 * @return array<int, string>
	 */
	private function statements(string $body): array {
		$statements = [''];
		$depth = 0;
		$quote = '';
		foreach (str_split($body) as $char) {
			if ($quote !== '' || $char === '"' || $char === "'") {
				$quote = $this->quoteAfter(quote: $quote, char: $char);
			} else if ($char === ';' && $depth === 0) {
				$statements[] = '';
				continue;
			}

			if ($quote === '') {
				$depth = max(0, ($depth + (self::NESTING[$char] ?? 0)));
			}

			$statements[(count($statements) - 1)] .= $char;
		}

		return $statements;
	}//end statements()


	/**
	 * The open quote after one character: a quote opens, the same quote closes.
	 *
	 * @param string $quote The quote open before it, or ''.
	 * @param string $char  The character.
	 *
	 * @return string
	 */
	private function quoteAfter(string $quote, string $char): string {
		if ($quote === '') {
			return $char;
		}

		if ($char === $quote) {
			return '';
		}

		return $quote;
	}//end quoteAfter()



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
			try {
				$service = $this->container->get($id);
			} catch (Throwable) {
				continue;
			}

			if (is_object($service) === true && method_exists($service, $method) === true) {
				return $service;
			}
		}

		return null;
	}//end service()
}//end class

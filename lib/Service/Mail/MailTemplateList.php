<?php

/**
 * Portaliq Mail Template List (mail-templates-admin-screen)
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Mail
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
 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t09
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Mail;

use OCA\Portaliq\Kernel\Contracts\MailTemplateRendererInterface;
use OCA\Portaliq\Service\ObjectServiceLookup;
use OCP\IL10N;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The rows of the Mail templates screen: one per kind of mail, merged with the
 * texts portals stored for it.
 *
 * A kind no portal changed reads as one row with the default text; a kind with
 * stored texts reads as one row per stored text, with its own subject, portal
 * and change date. So a fresh portal lists every kind it sends, as the
 * PtMailsjablonen board draws, instead of an empty list.
 *
 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t09
 */
class MailTemplateList {
	use ObjectServiceLookup;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For OpenRegister's ObjectService, resolved on use.
	 * @param LoggerInterface    $logger    Logs a read that failed.
	 * @param IL10N              $l10n      Names each kind in the reader's language.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The screen's rows, every kind first in the order the code declares them.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t09
	 */
	public function rows(): array {
		return self::merge(
			stored: $this->stored(),
			translate: fn (string $text): string => $this->l10n->t($text)
		);
	}//end rows()

	/**
	 * Merge the kinds of mail with the stored texts.
	 *
	 * A stored row of a kind the code no longer declares is left out: no mail
	 * would use it.
	 *
	 * @param array<int, mixed>              $stored    The stored `portalMailTemplate` rows.
	 * @param (callable(string): string)|null $translate Names a kind in the reader's language; null keeps the code's label.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t09
	 */
	public static function merge(array $stored, ?callable $translate=null): array {
		$byKey = [];
		foreach ($stored as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$byKey[(string)($row['templateKey'] ?? '')][] = $row;
		}

		$rows = [];
		foreach (MailTemplateRendererInterface::TEMPLATES as $key => $kind) {
			if ($translate !== null) {
				$kind['label'] = $translate((string)$kind['label']);
			}

			$own = ($byKey[$key] ?? []);
			if ($own === []) {
				$rows[] = self::defaultRow(key: $key, kind: $kind);
				continue;
			}

			foreach ($own as $row) {
				$rows[] = self::storedRow(key: $key, kind: $kind, row: $row);
			}
		}

		return $rows;
	}//end merge()

	/**
	 * The row of a kind no portal changed.
	 *
	 * @param string               $key  The template key.
	 * @param array<string, mixed> $kind The kind's declaration.
	 *
	 * @return array<string, mixed>
	 */
	private static function defaultRow(string $key, array $kind): array {
		return [
			'id' => 'default-'.$key,
			'stored' => false,
			'templateKey' => $key,
			'kindLabel' => (string)$kind['label'],
			'variableCount' => count($kind['variables']),
			'subject' => '',
			'portal' => '',
			'active' => true,
			'text' => 'default',
			'updated' => '',
			'updatedBy' => '',
		];
	}//end defaultRow()

	/**
	 * The row of a stored text.
	 *
	 * @param string               $key  The template key.
	 * @param array<string, mixed> $kind The kind's declaration.
	 * @param array<string, mixed> $row  The stored row.
	 *
	 * @return array<string, mixed>
	 */
	private static function storedRow(string $key, array $kind, array $row): array {
		$self = (array)($row['@self'] ?? []);

		return [
			'id' => self::idOf(row: $row),
			'stored' => true,
			'templateKey' => $key,
			'kindLabel' => (string)$kind['label'],
			'variableCount' => count($kind['variables']),
			'subject' => (string)($row['subject'] ?? ''),
			'body' => (string)($row['body'] ?? ''),
			'portal' => (string)($row['portal'] ?? ''),
			'active' => (($row['active'] ?? true) !== false),
			'text' => 'own',
			'updated' => (string)($self['updated'] ?? $row['updated'] ?? ''),
			'updatedBy' => (string)($row['updatedBy'] ?? ''),
		];
	}//end storedRow()

	/**
	 * A stored row's id: its uuid or id, flat or from `@self`.
	 *
	 * @param array<string, mixed> $row The stored row.
	 *
	 * @return string
	 */
	private static function idOf(array $row): string {
		foreach ([$row, (array)($row['@self'] ?? [])] as $source) {
			foreach (['uuid', 'id'] as $field) {
				$value = ($source[$field] ?? null);
				if (is_scalar($value) === true && (string)$value !== '') {
					return (string)$value;
				}
			}
		}

		return '';
	}//end idOf()

	/**
	 * Every stored text, of every portal.
	 *
	 * @return array<int, mixed>
	 */
	private function stored(): array {
		$service = $this->objectService();
		if ($service === null) {
			return [];
		}

		try {
			$rows = $service->findAll(
				config: [
					'filters' => ['register' => MailTemplateRenderer::REGISTER, 'schema' => MailTemplateRenderer::SCHEMA],
					'limit' => 500,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: the stored mail texts could not be read', ['reason' => $e->getMessage()]);
			return [];
		}

		$out = [];
		foreach ((array)($rows ?? []) as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$out[] = $row;
			}
		}

		return $out;
	}//end stored()
}//end class

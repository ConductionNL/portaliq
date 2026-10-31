<?php

/**
 * Portaliq Form Destination Dependents Listener
 *
 * Before OpenRegister saves a schema it asks every app which published forms
 * write into it (decision 179). Portaliq answers with one form per published
 * form binding whose destination is that schema, with the mapping a submit
 * of that form sends. OpenRegister judges each against the schema as it is
 * about to become, and either refuses the save or has the form unpublished
 * here (decision 181).
 *
 * @category Listener
 * @package  OCA\Portaliq\Listener
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
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-form-binding-names-its-destination-and-is-valid-against-it-req-pifo-007
 */

declare(strict_types=1);

namespace OCA\Portaliq\Listener;

use OCA\OpenRegister\Event\FormDestinationDependentsEvent;
use OCA\OpenRegister\Service\Form\FormDependent;
use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Intake\DestinationMapping;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Announces each published form binding that writes into the schema about to change.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-form-binding-names-its-destination-and-is-valid-against-it-req-pifo-007
 */
class FormDestinationDependentsListener implements IEventListener {
	private const REGISTER = 'portaliq';

	private const BINDINGS = 'portalFormBinding';

	/**
	 * Constructor.
	 *
	 * @param PortalFormBindingResolver $bindings Reads the published bindings and what each renders.
	 * @param DestinationMapping        $mapping  Builds the mapping a submit of a binding sends.
	 * @param PortalObjectWriter        $writer   Sets a binding back to draft.
	 * @param LoggerInterface           $logger   The logger.
	 */
	public function __construct(
		private readonly PortalFormBindingResolver $bindings,
		private readonly DestinationMapping $mapping,
		private readonly PortalObjectWriter $writer,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Add one form per published binding whose destination is the event's schema.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @listener-placement inline answer — the asker reads the event right after dispatch; a deferred answer comes after the save is judged.
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-form-binding-names-its-destination-and-is-valid-against-it-req-pifo-007
	 */
	public function handle(Event $event): void {
		if (($event instanceof FormDestinationDependentsEvent) === false) {
			return;
		}

		try {
			$schema     = $event->getSchema();
			$names      = $this->namesOf(schema: $schema);
			$properties = ['properties' => $schema->getProperties()];
			foreach ($this->bindings->allPublishedBindings() as $binding) {
				$form = $this->formFor(binding: $binding, names: $names, schema: $properties);
				if ($form !== null) {
					$event->addForm($form);
				}
			}
		} catch (Throwable $e) {
			// A schema save must not fail on portaliq's answer. The price is
			// that this save is judged without portaliq's forms, so say so loudly.
			$this->logger->error(
				'Portaliq: could not list the forms that write into a schema about to change',
				['reason' => $e->getMessage()]
			);
		}
	}//end handle()

	/**
	 * The form a binding stands for, or null when it does not write into this schema.
	 *
	 * A binding whose form lives on another host, and one that renders no
	 * form, write nothing and are left out.
	 *
	 * @param array<string, mixed> $binding The published binding.
	 * @param array<int, string>   $names   The names the schema answers to.
	 * @param array<string, mixed> $schema  The proposed schema, with `properties`.
	 *
	 * @return FormDependent|null
	 */
	private function formFor(array $binding, array $names, array $schema): ?FormDependent {
		$destination = $this->mapping->destinationOf(binding: $binding);
		$bindingId   = $this->idOf(row: $binding);
		if ($destination === null || $bindingId === '' || in_array($destination['schema'], $names, true) === false) {
			return null;
		}

		$render = $this->bindings->render(binding: $binding);
		if ((string)($render['kind'] ?? '') === PortalFormBindingResolver::KIND_EXTERNAL || ($render['resolvesToNoForm'] ?? false) === true) {
			return null;
		}

		$portal = (string)($binding['portal'] ?? '');

		return new FormDependent(
			id: $bindingId,
			app: Application::APP_ID,
			title: $this->titleOf(binding: $binding, render: $render),
			author: $this->authorOf(binding: $binding),
			mapping: $this->mapping->mappingFor(
				binding: $binding,
				fields: (array)($render['fields'] ?? []),
				schema: $schema,
				subjectRef: DestinationMapping::SUBJECT_PLACEHOLDER
			),
			unpublish: function () use ($bindingId, $portal): void {
				$this->unpublish(bindingId: $bindingId, portal: $portal);
			},
			// Every portal submit reaches OpenRegister as an anonymous write
			// (DestinationSubmit), so the form is judged as a public one.
			audience: 'public',
		);
	}//end formFor()

	/**
	 * The name an author knows the form by: the form's own, else the binding's, else its portal and route.
	 *
	 * @param array<string, mixed> $binding The binding.
	 * @param array<string, mixed> $render  What it renders to.
	 *
	 * @return string
	 */
	private function titleOf(array $binding, array $render): string {
		$candidates = [
			(string)($render['formName'] ?? ''),
			(string)($binding['formName'] ?? ''),
			(string)($binding['portal'] ?? '') . ' ' . (string)($binding['route'] ?? ''),
		];
		foreach ($candidates as $candidate) {
			if (trim($candidate) !== '') {
				return trim($candidate);
			}
		}

		return '';
	}//end titleOf()

	/**
	 * The user to tell when the form is unpublished: the binding's owner, when it has one.
	 *
	 * @param array<string, mixed> $binding The binding.
	 *
	 * @return string|null
	 */
	private function authorOf(array $binding): ?string {
		$owner = ($binding['@self']['owner'] ?? null);
		if (is_string($owner) === true && $owner !== '') {
			return $owner;
		}

		return null;
	}//end authorOf()

	/**
	 * Set a binding back to draft: it stops rendering, and keeps everything else.
	 *
	 * @param string $bindingId The binding.
	 * @param string $portal    The portal it belongs to.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the binding could not be written, so OpenRegister reports the failure.
	 */
	private function unpublish(string $bindingId, string $portal): void {
		$written = $this->writer->updateObject(
			register: self::REGISTER,
			schema: self::BINDINGS,
			scopeField: 'portal',
			subjectRef: $portal,
			organisation: '',
			id: $bindingId,
			data: ['status' => 'draft']
		);
		if ($written === null) {
			throw new RuntimeException('The form binding ' . $bindingId . ' could not be set to draft.');
		}
	}//end unpublish()

	/**
	 * The names a binding may use for this schema: its slug, uuid and id.
	 *
	 * @param object $schema The proposed schema.
	 *
	 * @return array<int, string>
	 */
	private function namesOf(object $schema): array {
		$names = [];
		foreach ([$schema->getSlug(), $schema->getUuid(), $schema->getId()] as $name) {
			if ($name !== null && (string)$name !== '') {
				$names[] = (string)$name;
			}
		}

		return $names;
	}//end namesOf()

	/**
	 * @param array<string, mixed> $row A stored row.
	 *
	 * @return string Its identifier, or ''.
	 */
	private function idOf(array $row): string {
		$self = (array)($row['@self'] ?? []);
		foreach ([($row['id'] ?? null), ($row['uuid'] ?? null), ($self['id'] ?? null), ($self['uuid'] ?? null)] as $candidate) {
			if ((is_string($candidate) === true || is_int($candidate) === true) && (string)$candidate !== '') {
				return (string)$candidate;
			}
		}

		return '';
	}//end idOf()
}//end class

<?php

/**
 * Portaliq Binding Destination Guard
 *
 * A published form binding must be valid against the destination it names
 * (decision 179, REQ-PIFO-007). Saving a binding as `published` runs
 * OpenRegister's form destination validator over its form; a binding with
 * findings is refused, from the first release (decision 181). A binding in
 * `draft` saves with its findings, which the preview shows (decision 189).
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

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Portaliq\Service\Intake\DestinationIntake;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Refuses publishing a form binding that its destination would refuse.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-form-binding-names-its-destination-and-is-valid-against-it-req-pifo-007
 */
class BindingDestinationGuardListener implements IEventListener {
	use SchemaWriteGuard;

	/**
	 * The slug of the schema this guard checks.
	 */
	private const GUARDED_SCHEMA = 'portalFormBinding';

	/**
	 * How this guard names itself in the log.
	 */
	private const GUARD_NAME = 'binding guard';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface        $container Resolves OpenRegister's schema mapper.
	 * @param PortalFormBindingResolver $bindings  Resolves what a binding renders.
	 * @param DestinationIntake         $intake    Checks a binding against its destination.
	 * @param IL10N                     $l10n      Translates the refusal.
	 * @param LoggerInterface           $logger    Logs a schema that could not be read.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly PortalFormBindingResolver $bindings,
		private readonly DestinationIntake $intake,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse the write when a published binding has findings.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The pre-write event.
	 * @param object                                  $entity The object being written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-form-binding-names-its-destination-and-is-valid-against-it-req-pifo-007
	 */
	protected function check(ObjectCreatingEvent|ObjectUpdatingEvent $event, object $entity): void {
		$errors = $this->refusalFor(schema: (string)$entity->getSchema(), binding: (array)($entity->getObject() ?? []));
		if ($errors === null) {
			return;
		}

		$event->stopPropagation();
		$event->setErrors($errors);
	}//end check()

	/**
	 * The errors that refuse this write, or null when it may go ahead: it is
	 * not a binding, not published, or its destination accepts its form.
	 *
	 * @param string               $schema  The written object's schema id.
	 * @param array<string, mixed> $binding The written object.
	 *
	 * @return array{message: string, findings: array<int, array<string, mixed>>}|null
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-form-binding-names-its-destination-and-is-valid-against-it-req-pifo-007
	 */
	public function refusalFor(string $schema, array $binding): ?array {
		if ((string)($binding['status'] ?? '') !== 'published' || $this->guardsSchema(schema: $schema) === false) {
			return null;
		}

		$findings = $this->intake->check(binding: $binding, render: $this->bindings->render(binding: $binding));
		if ($findings === []) {
			return null;
		}

		return [
			'message' => $this->l10n->t('This form cannot be published: its answers do not fit where they go. Fix the points below, or save it as a draft.'),
			'findings' => $findings,
		];
	}//end refusalFor()
}//end class

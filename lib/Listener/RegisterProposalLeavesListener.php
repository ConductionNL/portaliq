<?php

/**
 * Portaliq contributes its change-proposal queue to OpenRegister as two leaves.
 *
 * `portaliq-change-proposals` is the data half: the queued proposals on one
 * record, and a way to add one as a colleague, served from portaliq's own
 * register behind OpenRegister's per-object integrations route.
 * `portaliq-change-proposal-queue` is the render half: a widget and a tab under
 * one id, in which a reviewer accepts or rejects. Its JS half is
 * `src/integrations/registerProposalQueueLeaf.js`, loaded on other apps' pages
 * through the `portaliq-leaves` bundle.
 *
 * Neither leaf calls the app that places it (ADR-066 decision 2): accepting a
 * proposal writes the record through OpenRegister as the reviewer, and the
 * owning app sees an ordinary object update.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\Portaliq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/change-proposal-queue/specs/change-proposal-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Listener;

use OCA\OpenRegister\Event\RegisterLeafProvidersEvent;
use OCA\OpenRegister\Service\Integration\LeafDescriptor;
use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Proposals\ChangeProposalsProvider;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Registers the change-proposal data leaf and the review surface.
 *
 * @template-implements IEventListener<Event>
 */
class RegisterProposalLeavesListener implements IEventListener {

	/**
	 * The event OpenRegister dispatches to collect leaves, by name, so this
	 * app still boots on an instance without OpenRegister.
	 */
	public const EVENT = 'OCA\\OpenRegister\\Event\\RegisterLeafProvidersEvent';

	/**
	 * The render half's id, shared with the JS registration.
	 */
	public const QUEUE_LEAF_ID = 'portaliq-change-proposal-queue';

	/**
	 * Where the review surface renders. Duplicated verbatim in
	 * `src/integrations/registerProposalQueueLeaf.js`: two halves that name
	 * their surfaces differently drift apart with every check green.
	 */
	public const QUEUE_SURFACES = [
		'detail-page',
		'single-entity',
	];

	/**
	 * Constructor.
	 *
	 * @param ChangeProposalsProvider $provider The data half.
	 * @param IL10N                   $l10n     Translates the label.
	 * @param LoggerInterface         $logger   Records a leaf that could not register.
	 */
	public function __construct(
		private readonly ChangeProposalsProvider $provider,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Contribute both leaves.
	 *
	 * @param Event $event The leaf collection event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/change-proposal-queue/specs/change-proposal-queue/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof RegisterLeafProvidersEvent === false) {
			return;
		}

		try {
			$event->registerLeaf(
				new LeafDescriptor(
					id: ChangeProposalsProvider::ID,
					label: $this->l10n->t('Change proposals'),
					icon: ChangeProposalsProvider::ICON,
					kinds: [LeafDescriptor::KIND_DATA_PROVIDER],
					requiredApp: Application::APP_ID,
					group: ChangeProposalsProvider::GROUP,
				),
				$this->provider
			);

			$event->registerLeaf(
				new LeafDescriptor(
					id: self::QUEUE_LEAF_ID,
					label: $this->l10n->t('Change proposals'),
					icon: ChangeProposalsProvider::ICON,
					kinds: [LeafDescriptor::KIND_RENDER_SURFACE],
					requiredApp: Application::APP_ID,
					group: ChangeProposalsProvider::GROUP,
					surfaces: self::QUEUE_SURFACES,
					// Portaliq is Vue 3 and a host may be Vue 2.7: the JS half
					// roots its own app on a bare element. Both halves name the
					// same mode, or the host renders the leaf the wrong way.
					renderMode: LeafDescriptor::RENDER_MODE_MOUNT,
					loadStrategy: LeafDescriptor::LOADS_VIA_SHARED_ENTRY,
				),
				null
			);
		} catch (Throwable $e) {
			// Never take the leaf catalogue down: log, and lose only these.
			$this->logger->warning(
				'Portaliq could not register its change-proposal leaves: ' . $e->getMessage(),
				['exception' => $e]
			);
		}//end try
	}//end handle()
}//end class

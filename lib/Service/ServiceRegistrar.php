<?php

/**
 * Container bindings of the Service component
 *
 * @category Service
 * @package  OCA\Portaliq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec exclude Structural composition root, no functional requirement.
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Service\Messaging\GuardianMessagingLeafInterface;
use OCA\Portaliq\Service\Messaging\InAppMessagingLeaf;
use OCA\Portaliq\Service\Notifications\LoggingPushSender;
use OCA\Portaliq\Service\Notifications\PushSenderInterface;
use OCA\Portaliq\Service\Traffic\Geo\MmdbGeoResolver;
use OCA\Portaliq\Service\Traffic\GeoResolverInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Container bindings of the Service component.
 *
 * Application::register() hands the registration context to this class, so
 * the Service component states its own container bindings and AppInfo does
 * not name the classes behind them.
 *
 * @spec exclude Structural composition root, no functional requirement.
 */
final class ServiceRegistrar {

	/**
	 * Bind each interface the app's services ask for to its implementation.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude container wiring only: each alias belongs to the change that introduced its interface
	 */
	public function register(IRegistrationContext $context): void {
		(new ContractRegistrar())->register(context: $context);

		// Traffic analytics (portal-traffic-visitors-and-geo): where a
		// visitor's address turns into a region. The offline MMDB lookup is
		// bound here and the ingest path did not change from phase 0, which
		// bound the resolver that answered nothing. NullGeoResolver stays
		// for the tests and for an instance that wants no geography at all
		// (the settings provider `none` makes this resolver answer null too).
		$context->registerServiceAlias(GeoResolverInterface::class, MmdbGeoResolver::class);

		// Guardian-direct-messages: InAppMessagingLeaf is the FIRST
		// implementation of the messaging-leaf interface, backed by this
		// app's own OpenRegister schemas. When OpenRegister's planned
		// guardian-participant-messaging-leaf ships, a second
		// implementation can be aliased here instead, with no controller
		// change (design.md "Messaging leaf interface").
		$context->registerServiceAlias(GuardianMessagingLeafInterface::class, InAppMessagingLeaf::class);
		// Push-notifications-quiet-hours: LoggingPushSender is the FIRST
		// implementation of the push transport seam (a real Web Push
		// implementation needs VAPID key provisioning, an admin-settings
		// concern for a follow-up change — design.md "Messaging leaf
		// interface"). Alias here so a real transport can be swapped in
		// with no caller change.
		$context->registerServiceAlias(PushSenderInterface::class, LoggingPushSender::class);

		// Callers that only read scoped collections depend on the narrow read contract.
		$context->registerServiceAlias(ScopedCollectionReaderInterface::class, PortalObjectReader::class);
	}//end register()
}//end class

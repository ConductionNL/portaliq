<?php

/**
 * Bindings of the Kernel contracts to their Contribution implementations
 *
 * @category  Contribution
 * @package   OCA\Portaliq\Contribution
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract wiring, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

use OCA\Portaliq\Kernel\Contracts\CitizenDocumentUploadInterface;
use OCA\Portaliq\Kernel\Contracts\CitizenWriteConfigNormaliserInterface;
use OCA\Portaliq\Kernel\Contracts\ConfirmationMailKeysInterface;
use OCA\Portaliq\Kernel\Contracts\CrossRefConfigNormaliserInterface;
use OCA\Portaliq\Kernel\Contracts\FileFieldConfigNormaliserInterface;
use OCA\Portaliq\Kernel\Contracts\GuestActionRegistryInterface;
use OCA\Portaliq\Kernel\Contracts\NoticeRecipientNormaliserInterface;
use OCA\Portaliq\Kernel\Contracts\PortalContributionFilterInterface;
use OCA\Portaliq\Kernel\Contracts\PortalContributionRegistryInterface;
use OCA\Portaliq\Kernel\Contracts\PortalProviderLocatorInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Nextcloud autowires concrete classes but not interfaces, so every contract
 * in OCA\Portaliq\Kernel\Contracts that a caller type-hints needs an alias to
 * the class that implements it. An alias resolves to the same shared instance
 * as the implementation itself.
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 *
 * @spec exclude Structural contract wiring, no functional requirement.
 */
final class ContributionContractRegistrar {

	/**
	 * Alias every contract to its implementation.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec exclude Structural contract wiring, no functional requirement.
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(CitizenDocumentUploadInterface::class, CitizenDocumentUpload::class);
		$context->registerServiceAlias(CitizenWriteConfigNormaliserInterface::class, CitizenWriteConfigNormaliser::class);
		$context->registerServiceAlias(ConfirmationMailKeysInterface::class, ConfirmationMailKeys::class);
		$context->registerServiceAlias(CrossRefConfigNormaliserInterface::class, CrossRefConfigNormaliser::class);
		$context->registerServiceAlias(FileFieldConfigNormaliserInterface::class, FileFieldConfigNormaliser::class);
		$context->registerServiceAlias(GuestActionRegistryInterface::class, GuestActionRegistry::class);
		$context->registerServiceAlias(NoticeRecipientNormaliserInterface::class, NoticeRecipientNormaliser::class);
		$context->registerServiceAlias(PortalContributionFilterInterface::class, PortalContributionFilter::class);
		$context->registerServiceAlias(PortalContributionRegistryInterface::class, PortalContributionRegistry::class);
		$context->registerServiceAlias(PortalProviderLocatorInterface::class, PortalProviderLocator::class);
	}//end register()
}//end class

<?php

declare( strict_types = 1 );

// phpcs:disable Generic.NamingConventions.UpperCaseConstantName.ClassConstantNotUpperCase
namespace Miraheze\MirahezeRequests;

/**
 * A class containing constants representing the names of configuration variables,
 * to protect against typos.
 */
class ConfigNames {

	public const string AccountConfirmationExpiry = 'MirahezeRequestsAccountConfirmationExpiry';

	public const string AccountRequestsOpen = 'MirahezeRequestsAccountRequestsOpen';

	public const string IPVisibilityGroups = 'MirahezeRequestsIPVisibilityGroups';

	public const string IPRetentionDays = 'MirahezeRequestsIPRetentionDays';
}

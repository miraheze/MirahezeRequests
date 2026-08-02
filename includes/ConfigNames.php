<?php

// phpcs:disable Generic.NamingConventions.UpperCaseConstantName.ClassConstantNotUpperCase
namespace Miraheze\MirahezeRequests;

/**
 * A class containing constants representing the names of configuration variables,
 * to protect against typos.
 */
class ConfigNames {

	public const DatabaseSuffix = 'MirahezeRequestsDatabaseSuffix';

	public const EnableAutomatedJob = 'RenameWikiEnableAutomatedJob';

	public const HelpUrl = 'RenameWikiHelpUrl';

	public const IPVisibilityGroups = 'MirahezeRequestsIPVisibilityGroups';

	public const IPRetentionDays = 'MirahezeRequestsIPRetentionDays';

	public const ScriptCommand = 'RenameWikiScriptCommand';

	public const UsersNotifiedOnAllRequests = 'RenameWikiUsersNotifiedOnAllRequests';

	public const UsersNotifiedOnFailedRenameWikis = 'RenameWikiUsersNotifiedOnFailedRenameWikis';
}

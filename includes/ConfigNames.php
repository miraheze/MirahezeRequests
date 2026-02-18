<?php

// phpcs:disable Generic.NamingConventions.UpperCaseConstantName.ClassConstantNotUpperCase
namespace Miraheze\MirahezeRequests;

/**
 * A class containing constants representing the names of configuration variables,
 * to protect against typos.
 */
class ConfigNames {

	public const EnableAutomatedJob = 'RenameWikiEnableAutomatedJob';

	public const HelpUrl = 'RenameWikiHelpUrl';

	public const InterwikiMap = 'RenameWikiInterwikiMap';

	public const ScriptCommand = 'RenameWikiScriptCommand';

	public const UsersNotifiedOnAllRequests = 'RenameWikiUsersNotifiedOnAllRequests';

	public const UsersNotifiedOnFailedRenameWikis = 'RenameWikiUsersNotifiedOnFailedRenameWikis';
}

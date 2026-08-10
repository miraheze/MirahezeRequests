<?php

namespace Miraheze\MirahezeRequests;

interface MirahezeRequestsConstants {

	/**
	 * The name of the system user used to send notification emails and
	 * to perform automated actions (e.g. when a request fails and no
	 * human performer is available). Also reserved via
	 * UserGetReservedNames so a real account can never claim it.
	 */
	public const string SYSTEM_USER = 'MirahezeRequests';
}

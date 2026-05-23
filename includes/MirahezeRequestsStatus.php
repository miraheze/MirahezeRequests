<?php

namespace Miraheze\MirahezeRequests;

interface MirahezeRequestsStatus {

	public const string STATUS_COMPLETE = 'complete';

	public const string STATUS_DECLINED = 'declined';

	public const string STATUS_FAILED = 'failed';

	public const string STATUS_INPROGRESS = 'inprogress';

	public const string STATUS_PENDING = 'pending';

	public const string STATUS_STARTING = 'starting';

	public const array HTMLFORMOPTIONS = [
		'Pending' => self::STATUS_PENDING,
		'Starting' => self::STATUS_STARTING,
		'In progress' => self::STATUS_INPROGRESS,
		'Complete' => self::STATUS_COMPLETE,
		'Declined' => self::STATUS_DECLINED,
		'Failed' => self::STATUS_FAILED,
		'All' => '*',
	];
}

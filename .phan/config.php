<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['minimum_target_php_version'] = '8.4';

// TitleBlacklist and AntiSpoof are optional dependencies, used defensively
// (guarded by class_exists()) in SpecialRequestAccount for username
// validation. Neither is required by extension.json.
$cfg['directory_list'] = array_merge(
	$cfg['directory_list'], [
		'../../extensions/TitleBlacklist',
		'../../extensions/AntiSpoof',
	]
);

$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'], [
		'../../extensions/TitleBlacklist',
		'../../extensions/AntiSpoof',
	]
);

$cfg['suppress_issue_types'] = [
	'PhanAccessMethodInternal',
	'SecurityCheck-LikelyFalsePositive',
];

$cfg['plugins'][] = __DIR__ . '/../vendor/miraheze/phan-plugins/NoOptionalParamPlugin.php';

$cfg['enable_class_alias_support'] = false;

return $cfg;

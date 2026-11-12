<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['minimum_target_php_version'] = '8.4';

// optional dependencies, only used when loaded
$mediaWikiPath = getenv( 'MW_INSTALL_PATH' ) ?: '../..';
$optionalExtensions = array_map(
	static fn ( string $extension ): string => "$mediaWikiPath/extensions/$extension",
	[ 'AntiSpoof', 'CentralAuth', 'ConfirmEdit', 'TitleBlacklist' ]
);
$cfg['directory_list'] = array_merge(
	$cfg['directory_list'], $optionalExtensions
);

$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'], $optionalExtensions
);

$cfg['suppress_issue_types'] = [
	'PhanAccessMethodInternal',
	'SecurityCheck-LikelyFalsePositive',
];

$cfg['plugins'][] = __DIR__ . '/../vendor/miraheze/phan-plugins/NoOptionalParamPlugin.php';

$cfg['enable_class_alias_support'] = false;

return $cfg;

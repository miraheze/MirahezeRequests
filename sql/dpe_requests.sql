CREATE TABLE /*_*/dpe_requests (
	request_id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
	request_actor BIGINT(20) UNSIGNED NOT NULL,
	request_timestamp BINARY(14) NOT NULL,
	request_wiki VARCHAR(64) NOT NULL,
	request_reason ENUM (
		'complete', 'periodic', 'unavailable', 'other'
	) NOT NULL,
	request_explanation BLOB NOT NULL,
	request_status ENUM(
		'complete', 'declined', 'failed',
		'inprogress', 'pending', 'starting'
  	) NOT NULL,
	request_locked TINYINT UNSIGNED DEFAULT 0 NOT NULL,
	INDEX request_actor_timestamp (
    	request_actor, request_timestamp
  	),
	INDEX request_timestamp (request_timestamp),
	INDEX request_status (request_status),
	PRIMARY KEY(request_id)
); /*$wgDBTableOptions*/

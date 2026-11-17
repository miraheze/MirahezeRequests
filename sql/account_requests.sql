CREATE TABLE /*_*/account_requests (
	request_id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
	request_actor BIGINT(20) UNSIGNED NOT NULL,
	request_timestamp BINARY(14) NOT NULL,
	request_email VARCHAR(255) NOT NULL,
	request_username VARCHAR(255) NOT NULL,
	request_reason VARCHAR(255) NOT NULL,
	request_explanation BLOB NOT NULL,
	request_status VARCHAR(255) NOT NULL,
	request_locked TINYINT UNSIGNED DEFAULT 0 NOT NULL,
	INDEX request_actor_timestamp (
    	request_actor, request_timestamp
  	),
	INDEX request_timestamp (request_timestamp),
	INDEX request_email (request_email),
	INDEX request_username (request_username),
	INDEX request_status (request_status),
	PRIMARY KEY(request_id)
); /*$wgDBTableOptions*/

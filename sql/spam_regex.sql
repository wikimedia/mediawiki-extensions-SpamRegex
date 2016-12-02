CREATE TABLE /*_*/spam_regex (
	spam_id int(5) NOT NULL PRIMARY KEY AUTO_INCREMENT,
	spam_text varchar(255) NOT NULL,
	spam_timestamp char(14) NOT NULL,
	spam_user varchar(255) NOT NULL,
	spam_textbox int(1) NOT NULL default 1,
	spam_summary int(1) NOT NULL default 0,
	spam_reason varchar(255) NOT NULL
) /*$wgDBTableOptions*/;

CREATE UNIQUE INDEX /*i*/spam_text ON /*_*/spam_regex (spam_text);
CREATE INDEX /*i*/spam_timestamp ON /*_*/spam_regex (spam_timestamp);
CREATE INDEX /*i*/spam_user ON /*_*/spam_regex (spam_user);
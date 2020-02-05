DROP SEQUENCE IF EXISTS spam_regex_spam_id_seq CASCADE;
CREATE SEQUENCE spam_regex_spam_id_seq;

CREATE TABLE spam_regex (
	spam_id INTEGER NOT NULL PRIMARY KEY DEFAULT nextval('spam_regex_spam_id_seq'),
	spam_text TEXT NOT NULL,
	spam_timestamp TIMESTAMPTZ NOT NULL,
	spam_user TEXT NOT NULL,
	spam_textbox SMALLINT NOT NULL default 1,
	spam_summary SMALLINT NOT NULL default 0,
	spam_reason TEXT NOT NULL
);

ALTER SEQUENCE spam_regex_spam_id_seq OWNED BY spam_regex.spam_id;

CREATE UNIQUE INDEX spam_text ON spam_regex (spam_text);
CREATE INDEX spam_timestamp ON spam_regex (spam_timestamp);
CREATE INDEX spam_user ON spam_regex (spam_user);
<?php

use MediaWiki\MediaWikiServices;

/**
 * Utility class for managing the blocked phrases.
 *
 * @file
 */
class SpamRegex {
	// These merely make the code more readable
	public const TYPE_SUMMARY = 0;
	public const TYPE_TEXTBOX = 1;

	/**
	 * Add an entry to SpamRegex.
	 *
	 * @param string $phrase Phrase to block
	 * @param array $modes Block modes (textbox, summary)
	 * @param string $reason Reason for blocking the given phrase
	 * @param User $blocker User who is adding the phrase to SpamRegex
	 * @return Status
	 */
	public static function add( $phrase, $modes, $reason, $blocker ) {
		/* empty name */
		if ( strlen( $phrase ) == 0 ) {
			return Status::newFatal( wfMessage( 'spamregex-warning-1' )->escaped() );
		}

		/* validate expression */
		$simple_regex = self::validateRegex( $phrase );
		if ( !$simple_regex ) {
			return Status::newFatal( wfMessage( 'spamregex-error-1' )->escaped() );
		}

		/* we need at least one block mode specified... we can have them both, of course */
		$textbox = isset( $modes['text'] ) && $modes['text'];
		$summary = isset( $modes['summary'] ) && $modes['summary'];
		if ( !$textbox && !$summary ) {
			return Status::newFatal( wfMessage( 'spamregex-warning-2' )->escaped() );
		}

		/* make sure that we have a good reason for doing all this... */
		if ( !$reason ) {
			return Status::newFatal( wfMessage( 'spamregex-error-no-reason' )->escaped() );
		}

		/* insert to memc */
		if ( !empty( $textbox ) ) {
			self::updateMemcKeys( 'add', $phrase, 0 );
		}
		if ( !empty( $summary ) ) {
			self::updateMemcKeys( 'add', $phrase, 1 );
		}

		/* make insert to DB */
		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
		$dbw->insert(
			'spam_regex',
			[
				'spam_text' => $phrase,
				'spam_timestamp' => $dbw->timestamp( wfTimestampNow() ),
				'spam_user' => $blocker->getName(),
				'spam_textbox' => $textbox,
				'spam_summary' => $summary,
				'spam_reason' => $reason
			],
			__METHOD__,
			[ 'IGNORE' ]
		);

		/* duplicate entry */
		if ( !$dbw->affectedRows() ) {
			return Status::newFatal( wfMessage( 'spamregex-already-blocked', $phrase )->escaped() );
		} else {
			return Status::newGood();
		}
	}

	/**
	 * Delete a phrase from SpamRegex and update caches accordingly.
	 *
	 * @param string $phrase Phrase to delete from SpamRegex
	 * @return bool Operation status; true on success, false on failure
	 */
	public static function delete( $phrase ) {
		$text = urldecode( $phrase );

		/* delete in memc */
		self::updateMemcKeys( 'delete', $text );

		/* delete in DB */
		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
		$dbw->delete(
			'spam_regex',
			[ 'spam_text' => $text ],
			__METHOD__
		);

		return (bool)$dbw->affectedRows();
	}

	/**
	 * Fetch regex data for the given mode, either from cache, or failing
	 * that, then from the database.
	 *
	 * @param int $mode 0 = summary, 1 = textbox (use this class' TYPE_* constants)
	 * @return array Array of spamRegexed phrases (user-supplied string wrapped in forward
	 *  slashes and with the case insensitive (i) modifier as the suffix)
	 */
	public static function fetchRegexData( $mode ) {
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();

		$phrases = [];
		/* first, check if regex string is already stored in memcache */
		$key_clause = ( $mode == self::TYPE_TEXTBOX ) ? 'Textbox' : 'Summary';
		$key = self::getCacheKey( 'spamRegexCore', 'spamRegex', $key_clause );
		$cached = $cache->get( $key );

		if ( !$cached ) {
			/* fetch data from DB, concatenate into one string, then fill cache */
			$field = ( $mode == self::TYPE_TEXTBOX ? 'spam_textbox' : 'spam_summary' );
			$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
			$res = $dbr->select(
				'spam_regex',
				'spam_text',
				[ $field => 1 ],
				__METHOD__
			);

			// phpcs:ignore MediaWiki.ControlStructures.AssignmentInControlStructures.AssignmentInControlStructures
			while ( $row = $res->fetchObject() ) {
				$phrases[] = '/' . $row->spam_text . '/i';
			}

			$cache->set( $key, $phrases, 30 * 86400 );

			$res->free();
		} else {
			/* take from cache */
			$phrases = $cached;
		}

		return $phrases;
	}

	/**
	 * Get the correct cache key, depending on if we're on a wiki farm like
	 * setup where the spam_regex DB table is shared, or if we're on a
	 * single-wiki setup.
	 *
	 * @return string The proper memcached key, depending on whether spamRegex's DB table is shared or not
	 */
	public static function getCacheKey() {
		global $wgSharedDB, $wgSharedTables, $wgSharedPrefix;

		$args = func_get_args();
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();

		if ( in_array( 'spam_regex', $wgSharedTables ) ) {
			$args = array_merge( [ $wgSharedDB, $wgSharedPrefix ], $args );

			return call_user_func_array( [ $cache, 'makeGlobalKey' ], $args );
		} else {
			return call_user_func_array( [ $cache, 'makeKey' ], $args );
		}
	}

	/**
	 * Useful for cleaning the memcached keys
	 *
	 * @param string $action 'add' or 'delete', depending on what we're doing
	 * @param string $text String to be added or removed
	 * @param bool $mode 0 for textbox (=content), 1 for summary
	 */
	public static function updateMemcKeys( $action, $text, $mode = false ) {
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();

		if ( $mode === false ) {
			self::updateMemcKeys( $action, $text, 1 );
			self::updateMemcKeys( $action, $text, 0 );
		}

		// @todo FIXME: ...why is the logic for summary or textbox inverted here when compared
		// to the (brand new) class TYPE_* constants?!
		$key_clause = ( $mode == 1 ) ? 'Summary' : 'Textbox';
		$key = self::getCacheKey( 'spamRegexCore', 'spamRegex', $key_clause );
		$phrases = $cache->get( $key );

		if ( $phrases ) {
			$spam_text = '/' . $text . '/i';

			switch ( $action ) {
				case 'add':
					if ( !in_array( $spam_text, $phrases ) ) {
						$phrases[] = $spam_text;
					}
					break;
				case 'delete':
					$phrases = array_diff( $phrases, [ $spam_text ] );
					break;
			}

			$cache->set( $key, $phrases, 30 * 86400 );
		}
	}

	/**
	 * Validate the given regex
	 *
	 * @throws Exception when supplied an invalid regular expression
	 * @param string $text Regex to be validated
	 * @return bool False if exceptions were found, otherwise true
	 */
	public static function validateRegex( $text ) {
		try {
			// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
			$test = @preg_match( "/{$text}/", 'Whatever' );
			if ( !is_int( $test ) ) {
				throw new Exception( 'error!' );
			}
		} catch ( Exception $e ) {
			return false;
		}
		return true;
	}

}

<?php
/**
 * Hooked functions used by SpamRegex to add our magic to things like the edit
 * page and whatnot.
 *
 * @file
 */
class SpamRegexHooks {

	/**
	 * Main hook handler for edits
	 *
	 * @param EditPage $editPage
	 * @param string $text Page text
	 * @param $section
	 * @param string $error Error message, if any
	 * @param string $editSummary User-supplied edit summary
	 * @return bool True if the edit went through, false if it hit the spam filters
	 */
	public static function onEditFilter( $editPage, $text, $section, &$error, $editSummary ) {
		global $wgOut;

		$title = $editPage->getTitle();
		// allow blocked words to be added to whitelist
		if (
			$title->inNamespace( NS_MEDIAWIKI ) &&
			$title->getText() == 'Spam-whitelist'
		)
		{
			return true;
		}

		// here we get only the phrases for blocking in summaries...
		$s_phrases = self::fetchRegexData( 0 );

		if ( $s_phrases && ( $editPage->summary != '' ) ) {
			//	...so let's rock with our custom spamPage to indicate that
			//	(since some phrases can be safely in the text and not in a summary
			//	and we do not want to confuse the good users, right?)

			foreach ( $s_phrases as $s_phrase ) {
				if ( preg_match( $s_phrase, $editPage->summary, $s_matches ) ) {
					$wgOut->setPageTitle( wfMessage( 'spamprotectiontitle' ) );
					$wgOut->setRobotPolicy( 'noindex,nofollow' );
					$wgOut->setArticleRelated( false );

					$wgOut->addWikiMsg( 'spamprotectiontext' );
					$wgOut->addWikiMsg( 'spamprotectionmatch', "<nowiki>{$s_matches[0]}</nowiki>" );
					$wgOut->addWikiMsg( 'spamregex-summary' );

					$wgOut->returnToMain( false, $title );
					return false;
				}
			}
		}

		$t_phrases = [];
		// and here we check for phrases within the text itself
		$t_phrases = self::fetchRegexData( 1 );
		if ( $t_phrases && is_array( $t_phrases ) ) {
			foreach ( $t_phrases as $t_phrase ) {
				if ( preg_match( $t_phrase, $editPage->textbox1, $t_matches ) ) {
					$editPage->spamPageWithContent( $t_matches[0] );
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * This is for page move
	 *
	 * @param Title $oldTitle Old page title
	 * @param Title $newTitle New page title
	 * @param User $user User trying to move the page
	 * @param string $reason User-supplied reason for the move (if any)
	 * @param Status $status Status object to pass error messages to
	 * @return bool False if the summary contains spam, otherwise true
	 */
	public static function onMovePageCheckPermissions( $oldTitle, $newTitle, $user, $reason, $status ) {
		// here we get only the phrases for blocking in summaries...
		$phrases = self::fetchRegexData( 0 );

		if ( $phrases && $reason ) {
			foreach ( $phrases as $phrase ) {
				if ( preg_match( $phrase, $reason, $matches ) ) {
					// Quoth core MovePage::checkPermissions,
					// "This is kind of lame, won't display nice"
					$status->fatal( 'spamprotectiontext' );
					// Old code (which was used with the AbortMove hook, which
					// no longer exists in core):
					//$error .= wfMessage( 'spamregex-move' )->parse() . wfMessage( 'word_separator' )->escaped();
					//$error .= wfMessage( 'spamprotectionmatch', "<nowiki>{$matches[0]}</nowiki>" )->parse();
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Fetch regex data for the given mode, either from memcached, or failing
	 * that, then from the database.
	 *
	 * @todo Maybe consider moving to the SpamRegex class? Then again there are
	 * no outside callers for this method...
	 *
	 * @param int $mode 0 = summary, 1 = textbox
	 * @param int $db_master Use master database for fetching data?
	 * @return array Array of spamRegexed phrases
	 */
	protected static function fetchRegexData( $mode, $db_master = 0 ) {
		global $wgMemc;

		$phrases = [];
		/* first, check if regex string is already stored in memcache */
		$key_clause = ( $mode == 1 ) ? 'Textbox' : 'Summary';
		$key = SpamRegex::getCacheKey( 'spamRegexCore', 'spamRegex', $key_clause );
		$cached = $wgMemc->get( $key );

		if ( !$cached ) {
			/* fetch data from DB, concatenate into one string, then fill cache */
			$field = ( $mode == 1 ? 'spam_textbox' : 'spam_summary' );
			$dbr = wfGetDB( ( $db_master == 1 ) ? DB_MASTER : DB_REPLICA );
			$res = $dbr->select(
				'spam_regex',
				'spam_text',
				[ $field => 1 ],
				__METHOD__
			);
			while ( $row = $res->fetchObject() ) {
				$phrases[] = '/' . $row->spam_text . '/i';
			}
			$wgMemc->set( $key, $phrases, 30 * 86400 );
			$res->free();
		} else {
			/* take from cache */
			$phrases = $cached;
		}

		return $phrases;
	}

	/**
	 * Adds the new required database table into the database when the user
	 * runs /maintenance/update.php (the core database updater script).
	 *
	 * @param DatabaseUpdater $updater
	 * @return bool
	 */
	public static function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = __DIR__ . '/../sql';

		$dbType = $updater->getDB()->getType();

		$filename = 'spam_regex.sql';
		// For non-MySQL/MariaDB/SQLite DBMSes, use the appropriately named file
		/*
		if ( !in_array( $dbType, [ 'mysql', 'sqlite' ] ) ) {
			$filename = "spam_regex.{$dbType}.sql";
		}
		*/

		$updater->addExtensionTable( 'spam_regex', "{$dir}/{$filename}" );

		return true;
	}

	/**
	 * For integration with the Renameuser extension.
	 *
	 * Using this hook instead of RenameUserSQL because the spam_regex table
	 * lacks a user ID column.
	 *
	 * @param int $uid User ID
	 * @param string $oldName Old user name
	 * @param string $newName New user name
	 * @return bool
	 */
	public static function onRenameUserComplete( $uid, $oldName, $newName ) {
		$dbw = wfGetDB( DB_MASTER );

		$dbw->update(
			'spam_regex',
			[ 'spam_user' => $newName ],
			[ 'spam_user' => $oldName ],
			__METHOD__
		);

		return true;
	}
}
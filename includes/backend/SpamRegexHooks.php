<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;

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
	 *
	 * @param MediaWiki\Revision\RenderedRevision $renderedRevision
	 * @param MediaWiki\User\UserIdentity $user
	 * @param MediaWiki\CommentStore\CommentStoreComment $summary User-supplied edit summary
	 * @param int $flags Edit bit flags, like EDIT_BOT, if any
	 * @param MediaWiki\Status\Status $status Status object we'll be manipulating to prevent saving if necessary
	 * @return bool|void True if editing the spam whitelist or when no spam was detected, false if we have spam
	 */
	public static function onMultiContentSave( $renderedRevision, $user, $summary, $flags, $status ) {
		$rev = $renderedRevision->getRevision();
		$title = Title::newFromLinkTarget( $rev->getPageAsLinkTarget() );
		// allow blocked words to be added to whitelist
		if (
			$title->inNamespace( NS_MEDIAWIKI ) &&
			$title->getText() == 'Spam-whitelist'
		) {
			return true;
		}

		// here we get only the phrases for blocking in summaries...
		$s_phrases = SpamRegex::fetchRegexData( SpamRegex::TYPE_SUMMARY );

		if ( $s_phrases && ( $summary->text != '' ) ) {
			// ...so let's rock with our custom spamPage to indicate that
			// (since some phrases can be safely in the text and not in a summary,
			// and we do not want to confuse the good users, right?)

			foreach ( $s_phrases as $s_phrase ) {
				if ( preg_match( $s_phrase, $summary->text, $s_matches ) ) {
					$status->fatal( 'spamregex-summary-hit', $s_matches[0] );
					return false;
				}
			}
		}

		$t_phrases = [];
		// and here we check for phrases within the text itself
		$t_phrases = SpamRegex::fetchRegexData( SpamRegex::TYPE_TEXTBOX );
		if ( $t_phrases && is_array( $t_phrases ) ) {
			foreach ( $t_phrases as $t_phrase ) {
				$pageContent = $rev->getContent( SlotRecord::MAIN )->getText();
				if ( preg_match( $t_phrase, $pageContent, $t_matches ) ) {
					$status->fatal( 'spamprotectionmatch', $t_matches[0] );
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * This is for page move
	 *
	 * @param MediaWiki\Title\Title $oldTitle Old page title
	 * @param MediaWiki\Title\Title $newTitle New page title
	 * @param MediaWiki\User\User $user User trying to move the page
	 * @param string $reason User-supplied reason for the move (if any)
	 * @param Status $status Status object to pass error messages to
	 * @return bool False if the summary contains spam, otherwise true
	 */
	public static function onMovePageCheckPermissions(
		$oldTitle, $newTitle, $user, $reason, $status
	) {
		// here we get only the phrases for blocking in summaries...
		$phrases = SpamRegex::fetchRegexData( SpamRegex::TYPE_SUMMARY );

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
	 * Adds the new required database table into the database when the user
	 * runs /maintenance/update.php (the core database updater script).
	 *
	 * @param MediaWiki\Installer\DatabaseUpdater $updater
	 */
	public static function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = __DIR__ . '/../../sql';

		$dbType = $updater->getDB()->getType();

		$filename = 'spam_regex.sql';
		// For non-MySQL/MariaDB/SQLite DBMSes, use the appropriately named file
		if ( !in_array( $dbType, [ 'mysql', 'sqlite' ] ) ) {
			$filename = "spam_regex.{$dbType}.sql";
		}

		$updater->addExtensionTable( 'spam_regex', "{$dir}/{$filename}" );
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
	 */
	public static function onRenameUserComplete( $uid, $oldName, $newName ) {
		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );

		$dbw->update(
			'spam_regex',
			[ 'spam_user' => $newName ],
			[ 'spam_user' => $oldName ],
			__METHOD__
		);
	}

	/**
	 * For integration with the Comments extension, to make sure that Comments
	 * submitted via that extension are also run throug SpamRegex.
	 *
	 * @param string &$text Comment text
	 * @param bool &$retVal Is $text spammy?
	 */
	public static function onCommentsIsSpam( &$text, &$retVal ) {
		// @todo FIXME: duplicates onMultiContentSave() slightly
		$t_phrases = [];

		// and here we check for phrases within the text itself
		$t_phrases = SpamRegex::fetchRegexData( SpamRegex::TYPE_TEXTBOX );
		if ( $t_phrases && is_array( $t_phrases ) ) {
			foreach ( $t_phrases as $t_phrase ) {
				if ( preg_match( $t_phrase, $text, $t_matches ) ) {
					// We got a match -> it's spam, alright
					// The match is stored in $t_matches[0] but unlike the onMultiContentSave() code,
					// we don't need to know or care about that.
					// We'll just let Comments know, "yo, this is spam" and it'll take care of
					// the rest.
					$retVal = true;
				}
			}
		}
	}

	/**
	 * For integration with the ProblemReports extension, a ShoutWiki exclusive.
	 *
	 * @param string $text Problem report content
	 * @return bool False when we have spam and should abort processing; normally true
	 */
	public static function onProblemReportsContentCheck( $text ) {
		// @todo FIXME: like above, the code duplication is getting out of hand :-(
		$t_phrases = [];

		// and here we check for phrases within the text itself
		$t_phrases = SpamRegex::fetchRegexData( SpamRegex::TYPE_TEXTBOX );
		if ( $t_phrases && is_array( $t_phrases ) ) {
			foreach ( $t_phrases as $t_phrase ) {
				if ( preg_match( $t_phrase, $text, $t_matches ) ) {
					// We got a match -> it's spam, alright
					// The match is stored in $t_matches[0] but unlike the onMultiContentSave() code,
					// we don't need to know or care about that.
					// We'll just let ProblemReports know, "yo, this is spam" and it'll take care of
					// the rest.
					return false;
				}
			}
		}

		return true;
	}
}

<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Navigation\PrevNextNavigationRenderer;

/**
 * Backend logic for displaying and manipulating the list of blocked phrases
 *
 * @file
 */
class spamRegexList {
	/** @var int */
	public $numResults = 0;

	/**
	 * @var RequestContext RequestContext object passed by the SpecialPage
	 */
	public $context;

	/**
	 * Constructor
	 *
	 * @param string $par Parameter passed to the special page (phrase to be blocked) or null [unused here]
	 * @param RequestContext $context Context object from the SpecialPage
	 */
	function __construct( $par, $context ) {
		$this->context = $context;
	}

	/**
	 * Wrapper for GET values
	 *
	 * @param User $user
	 * @return array Array containing the limit and offset URL params & values
	 */
	public static function getListBits( User $user ) {
		global $wgRequest;

		if ( method_exists( $wgRequest, 'getLimitOffsetForUser' ) ) {
			// MW 1.35+
			list( $limit, $offset ) = $wgRequest->getLimitOffsetForUser( $user );
		} else {
			list( $limit, $offset ) = $wgRequest->getLimitOffset();
		}
		$bits = [
			'limit' => $limit,
			'offset' => $offset
		];

		return $bits;
	}

	/**
	 * Output list of blocked expressions
	 *
	 * @param string $err Error message, if any
	 */
	function showList( $err = '' ) {
		$out = $this->context->getOutput();
		$user = $this->context->getUser();

		/* on error, display error */
		if ( $err != '' ) {
			$out->addHTML( "<p class=\"error\">{$err}</p>\n" );
		}

		$out->addWikiMsg( 'spamregex-currently-blocked' );

		/* get data and play with data */
		if ( !$this->fetchNumResults() ) {
			$out->addWikiMsg( 'spamregex-no-currently-blocked' );
		} else {
			$dbr = wfGetDB( DB_REPLICA );
			$titleObj = SpecialPage::getTitleFor( 'SpamRegex' );
			$action = htmlspecialchars( $titleObj->getLocalURL( self::getListBits( $user ) ) );
			$action_unblock = htmlspecialchars( $titleObj->getLocalURL(
				[ 'action' => 'delete' ] + self::getListBits( $user )
			) );

			$request = $this->context->getRequest();
			if ( method_exists( $request, 'getLimitOffsetForUser' ) ) {
				// MW 1.35+
				list( $limit, $offset ) = $request->getLimitOffsetForUser( $user );
			} else {
				list( $limit, $offset ) = $request->getLimitOffset();
			}

			$this->showPrevNext( $out );
			$out->addHTML( "<form name=\"spamregexlist\" method=\"get\" action=\"{$action}\">" );

			$res = $dbr->select(
				'spam_regex',
				'*',
				[],
				__METHOD__,
				[
					'LIMIT' => $limit,
					'OFFSET' => $offset,
					'ORDER BY' => 'spam_timestamp DESC'
				]
			);

			$lang = $this->context->getLanguage();
			// phpcs:ignore MediaWiki.ControlStructures.AssignmentInControlStructures.AssignmentInControlStructures
			while ( $row = $res->fetchObject() ) {
				$date = $lang->date( wfTimestamp( TS_MW, $row->spam_timestamp ), true );
				$time = $lang->time( wfTimestamp( TS_MW, $row->spam_timestamp ), true );
				$unblock_phrase = urlencode( $row->spam_text );
				$desc = '';

				if ( $row->spam_textbox == 1 ) {
					$desc .= $this->context->msg( 'spamregex-text' )->plain();
				}

				if ( $row->spam_summary == 1 ) {
					$desc .= $this->context->msg( 'spamregex-summary-log' )->plain();
					if ( $row->spam_textbox == 1 ) {
						$desc = $this->context->msg( 'spamregex-text-and-summary-log' )->plain();
					}
				}

				$out->addHTML( '<ul class="spamregex-entry">' );
				$out->addWikiMsg(
					'spamregex-log',
					$row->spam_text,
					$desc,
					$action_unblock,
					$unblock_phrase,
					$row->spam_user,
					$date,
					$time,
					$row->spam_reason
				);
				$out->addHTML( '</ul>' );
			}

			$res->free();
			$out->addHTML( '</form>' );
			$this->showPrevNext( $out );
		}
	}

	/**
	 * Show the form for deleting a blocked entry.
	 * Primarily meant as a fallback for no-JS users clicking on a "remove" link.
	 */
	function showDeletionForm() {
		$titleObj = SpecialPage::getTitleFor( 'SpamRegex' );
		$this->context->getOutput()->addHTML(
			Html::openElement( 'form', [ 'method' => 'post', 'action' => $titleObj->getFullURL() ] ) .
				'<br />' .
				$this->context->msg( 'spamregex-unblock-form-text' )->escaped() .
				$this->context->msg( 'word-separator' )->escaped() .
				'<br />' .
				Html::input( 'text', $this->context->getRequest()->getVal( 'text' ) ) .
				Html::hidden( 'action', 'delete' ) .
				Html::hidden( 'token', $this->context->getUser()->getEditToken() ) .
				Html::submitButton( $this->context->msg( 'ipusubmit' )->escaped(), [ 'id' => 'spamregex-submit-btn' ] ) .
				'<br />' .
			Html::closeElement( 'form' )
		);
	}

	/**
	 * Remove from list - without confirmation
	 */
	function deleteFromList() {
		$request = $this->context->getRequest();
		$text = $request->getVal( 'text' );
		$token = $request->getVal( 'token' );

		$titleObj = SpecialPage::getTitleFor( 'SpamRegex' );

		if ( $this->context->getUser()->matchEditToken( $token ) ) {
			if ( SpamRegex::delete( $text ) ) {
				/* success */
				$action = 'success_unblock';
			} else {
				/* text doesn't exist */
				$action = 'failure_unblock';
			}
		} else {
			$action = 'sessionfailure';
		}

		$this->context->getOutput()->redirect( $titleObj->getFullURL(
			[ 'action' => $action, 'text' => $text ] + self::getListBits(
				$this->context->getUser()
			)
		) );
	}

	/**
	 * Fetch number of all rows
	 * Use memcached if possible
	 *
	 * @return int Amount of results in the spam_regex database table
	 */
	function fetchNumResults() {
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();

		/* we use memcached here */
		$key = SpamRegex::getCacheKey( 'spamRegexCore', 'numResults' );
		$cached = $cache->get( $key );

		if ( !$cached || $cached === null || $cached === false ) {
			$dbr = wfGetDB( DB_REPLICA );
			$results = $dbr->selectField( 'spam_regex', 'COUNT(*)', '', __METHOD__ );
			$cache->set( $key, $results, 30 * 86400 );
		} else {
			$results = $cached;
		}

		$this->numResults = $results;

		return $results;
	}

	/** on success */
	function showSuccess() {
		$out = $this->context->getOutput();
		$out->setPageTitle( $this->context->msg( 'spamregex-page-title-1' ) );
		$out->setSubTitle( $this->context->msg( 'spamregex-unblock-success' )->escaped() );
		$out->addWikiMsg( 'spamregex-unblock-message', $this->context->getRequest()->getVal( 'text' ) );
	}

	/**
	 * Init for showPrevNext
	 * @param OutputPage &$out
	 */
	function showPrevNext( OutputPage &$out ) {
		$request = $this->context->getRequest();
		if ( method_exists( $request, 'getLimitOffsetForUser' ) ) {
			// MW 1.35+
			list( $limit, $offset ) = $request->getLimitOffsetForUser(
				$this->context->getUser()
			);
		} else {
			list( $limit, $offset ) = $request->getLimitOffset();
		}

		if ( class_exists( 'MediaWiki\Navigation\PagerNavigationBuilder' ) ) {
			// MW 1.39+
			$navBuilder = new MediaWiki\Navigation\PagerNavigationBuilder( $this->context );
			$navBuilder
				->setPage( $this->context->getTitle() )
				->setLinkQuery( [ 'limit' => $limit, 'offset' => $offset ] )
				->setLimitLinkQueryParam( 'limit' )
				->setCurrentLimit( $limit )
				->setPrevTooltipMsg( 'prevn-title' )
				->setNextTooltipMsg( 'nextn-title' )
				->setLimitTooltipMsg( 'shown-title' );

			if ( $offset > 0 ) {
				$navBuilder->setPrevLinkQuery( [ 'offset' => (string)max( $offset - $limit, 0 ) ] );
			}

			$atEnd = ( $this->numResults - $offset ) <= $limit;
			if ( !$atEnd ) {
				$navBuilder->setNextLinkQuery( [ 'offset' => (string)( $offset + $limit ) ] );
			}

			$html = $navBuilder->getHtml();
		} else {
			$prevNext = new PrevNextNavigationRenderer( $this->context );
			$html = $prevNext->buildPrevNextNavigation(
				$this->context->getTitle(),
				$offset,
				$limit,
				[],
				( $this->numResults - $offset ) <= $limit
			);
		}

		$out->addHTML( '<p>' . $html . '</p>' );
	}
}

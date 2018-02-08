<?php
/**
 * Backend logic for displaying and manipulating the list of blocked phrases
 *
 * @file
 */
class spamRegexList {
	public $numResults = 0;

	/**
	 * @var RequestContext $context RequestContext object passed by the SpecialPage
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
	 * @return array Array containing the limit and offset URL params & values
	 */
	public static function getListBits() {
		global $wgRequest;

		list( $limit, $offset ) = $wgRequest->getLimitOffset();
		$bits = array(
			'limit' => $limit,
			'offset' => $offset
		);

		return $bits;
	}

	/**
	 * Output list of blocked expressions
	 *
	 * @param string $err Error message, if any
	 */
	function showList( $err = '' ) {
		$out = $this->context->getOutput();

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
			$action = htmlspecialchars( $titleObj->getLocalURL( self::getListBits() ) );
			$action_unblock = htmlspecialchars( $titleObj->getLocalURL(
				array( 'action' => 'delete' ) + self::getListBits()
			) );
			list( $limit, $offset ) = $this->context->getRequest()->getLimitOffset();

			$this->showPrevNext( $out );
			$out->addHTML( "<form name=\"spamregexlist\" method=\"get\" action=\"{$action}\">" );

			$res = $dbr->select(
				'spam_regex',
				'*',
				array(),
				__METHOD__,
				array(
					'LIMIT' => $limit,
					'OFFSET' => $offset,
					'ORDER BY' => 'spam_timestamp DESC'
				)
			);

			$lang = $this->context->getLanguage();
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

				$out->addHTML( '<ul>' );
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

	/* remove from list - without confirmation */
	function deleteFromList() {
		$text = $this->context->getRequest()->getVal( 'text' );

		$titleObj = SpecialPage::getTitleFor( 'SpamRegex' );

		if ( SpamRegex::delete( $text ) ) {
			/* success */
			$action = 'success_unblock';
		} else {
			/* text doesn't exist */
			$action = 'failure_unblock';
		}

		$this->context->getOutput()->redirect( $titleObj->getFullURL(
			array( 'action' => $action, 'text' => $text ) + self::getListBits()
		) );
	}

	/**
	 * Fetch number of all rows
	 * Use memcached if possible
	 *
	 * @return int Amount of results in the spam_regex database table
	 */
	function fetchNumResults() {
		global $wgMemc;

		/* we use memcached here */
		$key = SpamRegex::getCacheKey( 'spamRegexCore', 'numResults' );
		$cached = $wgMemc->get( $key );
		$results = 0;

		if ( !$cached || is_null( $cached ) || $cached === false ) {
			$dbr = wfGetDB( DB_REPLICA );
			$results = $dbr->selectField( 'spam_regex', 'COUNT(*)', '', __METHOD__ );
			$wgMemc->set( $key, $results, 30 * 86400 );
		} else {
			$results = $cached;
		}

		$this->numResults = $results;

		return $results;
	}

	/* on success */
	function showSuccess() {
		$out = $this->context->getOutput();
		$out->setPageTitle( $this->context->msg( 'spamregex-page-title-1' ) );
		$out->setSubTitle( $this->context->msg( 'spamregex-unblock-success' )->escaped() );
		$out->addWikiMsg( 'spamregex-unblock-message', $this->context->getRequest()->getVal( 'text' ) );
	}

	/* init for showPrevNext */
	function showPrevNext( &$out ) {
		list( $limit, $offset ) = $this->context->getRequest()->getLimitOffset();
		$html = $this->context->getLanguage()->viewPrevNext(
			$this->context->getTitle(),
			$offset,
			$limit,
			array(),
			( $this->numResults - $offset ) <= $limit
		);
		$out->addHTML( '<p>' . $html . '</p>' );
	}
}
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
	 * Useful for cleaning the memcached keys
	 *
	 * @param string $action 'add' or 'delete', depending on what we're doing
	 * @param string $text String to be added or removed
	 * @param bool $mode 0 for textbox (=content), 1 for summary
	 */
	public static function updateMemcKeys( $action, $text, $mode = false ) {
		global $wgMemc;

		if ( $mode === false ) {
			self::updateMemcKeys( $action, $text, 1 );
			self::updateMemcKeys( $action, $text, 0 );
		}

		$key_clause = ( $mode == 1 ) ? 'Summary' : 'Textbox';
		$key = SpamRegexHooks::getCacheKey( 'spamRegexCore', 'spamRegex', $key_clause );
		$phrases = $wgMemc->get( $key );

		if ( $phrases ) {
			$spam_text = '/' . $text . '/i';

			switch ( $action ) {
				case 'add':
					if ( !in_array( $spam_text, $phrases ) ) {
						$phrases[] = $spam_text;
					}
					break;
				case 'delete':
					$phrases = array_diff( $phrases, array( $spam_text ) );
					break;
			}

			$wgMemc->set( $key, $phrases, 0 );
		}
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
			$dbr = wfGetDB( DB_SLAVE );
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
		$text = urldecode( $this->context->getRequest()->getVal( 'text' ) );

		/* delete in memc */
		self::updateMemcKeys( 'delete', $text );

		/* delete in DB */
		$dbw = wfGetDB( DB_MASTER );
		$dbw->delete(
			'spam_regex',
			array( 'spam_text' => $text ),
			__METHOD__
		);
		$titleObj = SpecialPage::getTitleFor( 'SpamRegex' );

		if ( $dbw->affectedRows() ) {
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
		$key = SpamRegexHooks::getCacheKey( 'spamRegexCore', 'numResults' );
		$cached = $wgMemc->get( $key );
		$results = 0;

		if ( !$cached || is_null( $cached ) || $cached === false ) {
			$dbr = wfGetDB( DB_SLAVE );
			$results = $dbr->selectField( 'spam_regex', 'COUNT(*)', '', __METHOD__ );
			$wgMemc->set( $key, $results, 0 );
		} else {
			$results = $cached;
		}

		$this->numResults = $results;

		return $results;
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
			$test = @preg_match( "/{$text}/", 'Whatever' );
			if ( !is_int( $test ) ) {
				throw new Exception( 'error!' );
			}
		} catch ( Exception $e ) {
			return false;
		}
		return true;
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
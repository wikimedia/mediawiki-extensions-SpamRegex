<?php
/**
 * Backend logic for the form for blocking phrases
 *
 * @file
 */
class spamRegexForm {
	/**
	 * @var string $mBlockedPhrase The phrase to be blocked
	 */
	public $mBlockedPhrase;

	/**
	 * @var string $mBlockedReason Reason for blocking a phrase
	 */
	public $mBlockedReason;

	/**
	 * @var bool $mBlockedTextbox Is the phrase to be blocked in article text?
	 */
	public $mBlockedTextbox;

	/**
	 * @var bool $mBlockedTextbox Is the phrase to be blocked in edit summaries?
	 */
	public $mBlockedSummary;

	/**
	 * @var RequestContext $context RequestContext object passed by the SpecialPage
	 */
	public $context;

	/**
	 * Constructor
	 *
	 * @param string $par Parameter passed to the special page (phrase to be blocked) or null
	 * @param RequestContext $context Context object from the SpecialPage
	 */
	function __construct( $par, $context ) {
		$request = $context->getRequest();
		// urldecode() to avoid *displaying* \ as %5C etc.; even w/o it the
		// entries are saved correctly to the DB
		// and trim() to avoid unwanted trailing whitespace in blocked entries
		$this->mBlockedPhrase = trim( $request->getVal( 'wpBlockedPhrase', urldecode( $request->getVal( 'text', $par ) ) ) );
		$this->mBlockedReason = $request->getVal( 'wpBlockedReason' );
		$this->mBlockedTextbox = $request->getCheck( 'wpBlockedTextbox' ) ? 1 : 0;
		$this->mBlockedSummary = $request->getCheck( 'wpBlockedSummary' ) ? 1 : 0;
		$this->context = $context;
	}

	/* output */
	function showForm( $err = '' ) {
		$out = $this->context->getOutput();

		$token = htmlspecialchars( $this->context->getUser()->getEditToken() );
		$titleObj = SpecialPage::getTitleFor( 'SpamRegex' );
		$action = htmlspecialchars( $titleObj->getLocalURL(
			array( 'action' => 'submit' ) + spamRegexList::getListBits()
		) );

		if ( $err != '' ) {
			$out->setSubtitle( $this->context->msg( 'formerror' )->escaped() );
			$out->addHTML( "<p class=\"error\">{$err}</p>\n" );
		}

		$out->addWikiMsg( 'spamregex-intro' );

		if ( $this->context->getRequest()->getVal( 'action' ) == 'submit' ) {
			$scBlockedPhrase = htmlspecialchars( $this->mBlockedPhrase );
		} else {
			$scBlockedPhrase = '';
		}

		// Add JS
		$out->addModules( 'ext.spamRegex.js' );

		// Output the UI template
		$template = new SpamRegexUITemplate;
		$template->set( 'action', $action );
		$template->set( 'scBlockedPhrase', $scBlockedPhrase );
		$template->set( 'token', $token );
		$out->addTemplate( $template );
	}

	/* on success */
	function showSuccess() {
		$out = $this->context->getOutput();
		$out->setPageTitle( $this->context->msg( 'spamregex-page-title-2' ) );
		$out->setSubTitle( $this->context->msg( 'spamregex-block-success' ) );
		$out->addWikiMsg( 'spamregex-block-message', $this->mBlockedPhrase );
	}

	/* on submit */
	function doSubmit() {
		/* empty name */
		if ( strlen( $this->mBlockedPhrase ) == 0 ) {
			$this->showForm( $this->context->msg( 'spamregex-warning-1' )->escaped() );
			return;
		}

		/* validate expression */
		$simple_regex = spamRegexList::validateRegex( $this->mBlockedPhrase );
		if ( !$simple_regex ) {
			$this->showForm( $this->context->msg( 'spamregex-error-1' )->escaped() );
			return;
		}

		/* we need at least one block mode specified... we can have them both, of course */
		if ( !$this->mBlockedTextbox && !$this->mBlockedSummary ) {
			$this->showForm( $this->context->msg( 'spamregex-warning-2' )->escaped() );
			return;
		}

		/* make sure that we have a good reason for doing all this... */
		if ( !$this->mBlockedReason ) {
			$this->showForm( $this->context->msg( 'spamregex-error-no-reason' )->escaped() );
			return;
		}

		/* insert to memc */
		if ( !empty( $this->mBlockedTextbox ) ) {
			spamRegexList::updateMemcKeys( 'add', $this->mBlockedPhrase, 0 );
		}
		if ( !empty( $this->mBlockedSummary ) ) {
			spamRegexList::updateMemcKeys( 'add', $this->mBlockedPhrase, 1 );
		}

		/* make insert to DB */
		$dbw = wfGetDB( DB_MASTER );
		$dbw->insert(
			'spam_regex',
			array(
				'spam_text' => $this->mBlockedPhrase,
				'spam_timestamp' => wfTimestampNow(),
				'spam_user' => $this->context->getUser()->getName(),
				'spam_textbox' => $this->mBlockedTextbox,
				'spam_summary' => $this->mBlockedSummary,
				'spam_reason' => $this->mBlockedReason
			),
			__METHOD__,
			array( 'IGNORE' )
		);

		/* duplicate entry */
		if ( !$dbw->affectedRows() ) {
			$this->showForm( $this->context->msg( 'spamregex-already-blocked', $this->mBlockedPhrase )->escaped() );
			return;
		}

		/* redirect */
		$titleObj = SpecialPage::getTitleFor( 'SpamRegex' );
		$this->context->getOutput()->redirect( $titleObj->getFullURL(
			array( 'action' => 'success_block', 'text' => urlencode( $this->mBlockedPhrase ) ) +
				spamRegexList::getListBits()
		) );
	}
}
<?php
/**
 * Logic for the form for blocking phrases
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
	 * @var bool $mBlockedSummary Is the phrase to be blocked in edit summaries?
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
			[ 'action' => 'submit' ] + spamRegexList::getListBits()
		) );

		if ( $err != '' ) {
			$out->setSubtitle( $this->context->msg( 'formerror' )->escaped() );
			$out->wrapWikiMsg( "<p class=\"error\">$1</p>\n", $err );
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
		$modes = [];
		if ( $this->mBlockedTextbox ) {
			$modes['text'] = true;
		}
		if ( $this->mBlockedSummary ) {
			$modes['summary'] = true;
		}

		$status = SpamRegex::add(
			$this->mBlockedPhrase,
			$modes,
			$this->mBlockedReason,
			$this->context->getUser()
		);

		if ( !$status->isGood() ) {
			$this->showForm( $status->getWikiText() );
			return;
		}

		/* redirect */
		$titleObj = SpecialPage::getTitleFor( 'SpamRegex' );
		$this->context->getOutput()->redirect( $titleObj->getFullURL(
			[ 'action' => 'success_block', 'text' => urlencode( $this->mBlockedPhrase ) ] +
				spamRegexList::getListBits()
		) );
	}
}
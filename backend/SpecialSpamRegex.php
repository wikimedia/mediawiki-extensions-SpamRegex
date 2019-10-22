<?php

class SpecialSpamRegex extends SpecialPage {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'SpamRegex', 'spamregex' );
	}

	/**
	 * Group this special page under the correct header in Special:SpecialPages.
	 *
	 * @return string
	 */
	protected function getGroupName() {
		return 'pagetools';
	}

	// @see https://phabricator.wikimedia.org/T123591
	public function doesWrites() {
		return true;
	}

	/**
	 * Show the special page
	 *
	 * @param mixed|null $par Parameter passed to the page
	 */
	public function execute( $par ) {
		$out = $this->getOutput();
		$request = $this->getRequest();
		$user = $this->getUser();

		// Check permissions
		if ( !$user->isAllowed( 'spamregex' ) ) {
			throw new PermissionsError( 'spamregex' );
		}

		// Show a message if the database is in read-only mode
		$this->checkReadOnly();

		// If user is blocked, they don't need to access this page
		if ( $user->getBlock() ) {
			throw new UserBlockedError( $user->getBlock() );
		}

		// Set the page title and other stuff
		$this->setHeaders();
		$out->setPageTitle( $this->msg( 'spamregex-page-title' ) );

		$sRF = new spamRegexForm( $par, $this->getContext() );
		$sRL = new spamRegexList( $par, $this->getContext() );

		$action = $request->getVal( 'action' );
		if ( $action == 'success_block' ) {
			$sRF->showSuccess();
			$sRF->showForm();
		} elseif ( $action == 'success_unblock' ) {
			$sRL->showSuccess();
			$sRF->showForm();
		} elseif ( $action == 'failure_unblock' ) {
			$text = htmlspecialchars( $request->getVal( 'text' ) );
			$sRF->showForm( $this->msg( 'spamregex-error-unblocking', $text )->escaped() );
		} elseif ( $request->wasPosted() && $action == 'submit' &&
			$user->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
			$sRF->doSubmit();
		} elseif ( $action == 'delete' ) {
			$sRL->deleteFromList();
		} else {
			$sRF->showForm();
		}

		$sRL->showList();
	}
}
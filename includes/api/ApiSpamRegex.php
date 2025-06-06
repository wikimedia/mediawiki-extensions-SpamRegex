<?php
/**
 * API module for adding entries to SpamRegex and removing previously blocked
 * entries.
 *
 * @file
 * @ingroup API
 * @see https://www.mediawiki.org/wiki/API:Extensions#ApiSampleApiExtension.php
 */

use Wikimedia\ParamValidator\ParamValidator;

class ApiSpamRegex extends MediaWiki\Api\ApiBase {

	/**
	 * Main entry point.
	 * @return bool
	 */
	public function execute() {
		// Check permission first before proceeding any further
		$this->checkUserRightsAny( 'spamregex' );

		// Get the request parameters
		$params = $this->extractRequestParams();

		$do = $params['do'];
		$phrase = $params['phrase'];
		// Per code review, let's explicitly check for this.
		// I mean, you *could* block this number but that's kinda like blocking the
		// letter "a" or something equally widely used...
		if ( !$phrase === '0' ) {
			$this->dieWithError( 'spamregex-warning-1' );
		}

		// Need at least one mode (but both are OK, too!) when adding stuff
		if ( $do === 'add' ) {
			if ( !$params['modes'] ) {
				$this->dieWithError( 'spamregex-warning-2' );
			}
			$modes = array_fill_keys( $params['modes'], true );
			// Reason validation is done in SpamRegex::add()...kinda inconsistent, I must say.
		}

		$output = '';
		// Decide what function to call
		if ( $do === 'add' ) {
			$user = $this->getUser();
			$result = SpamRegex::add( $phrase, $modes, $params['reason'], $user );
			if ( $result->isGood() ) {
				$output = 'ok';
			} else {
				$output = $result->getWikiText();
			}
		} elseif ( $do === 'delete' ) {
			$result = SpamRegex::delete( $phrase );
			if ( $result === true ) {
				$output = 'ok';
			} else {
				$output = 'error';
			}
		}

		// Top level
		$this->getResult()->addValue( null, $this->getModuleName(),
			[ 'result' => $output ]
		);

		return true;
	}

	public function mustBePosted() {
		return true;
	}

	public function isWriteMode() {
		return true;
	}

	public function needsToken() {
		return 'csrf';
	}

	/**
	 * @return array
	 */
	public function getAllowedParams() {
		return [
			'do' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_TYPE => [
					'add',
					'delete'
				],
				ParamValidator::PARAM_REQUIRED => true
			],
			'modes' => [
				ParamValidator::PARAM_TYPE => [
					'text',
					'summary'
				],
				ParamValidator::PARAM_ISMULTI => true
			],
			'phrase' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true
			],
			'reason' => [
				ParamValidator::PARAM_TYPE => 'string'
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	protected function getExamplesMessages() {
		return [
			'action=spamregex&do=add&phrase=FooBar&reason=Cross-wiki%20spam&modes=summary|text&token=123ABC' => 'apihelp-spamregex-example-1',
			'action=spamregex&do=delete&phrase=Test,%20just%20a%20test&token=123ABC' => 'apihelp-spamregex-example-2'
		];
	}
}

<?php

/**
 * @covers spamRegexList
 * @group Database
 */
class SpamRegexListTest extends MediaWikiIntegrationTestCase {

	public function testShowPrevNext() {
		$context = new RequestContext();

		$context->setUser( $this->getTestUser( [ 'staff' ] )->getUser() );
		$context->setTitle( Title::newFromText( 'Special:SpamRegex' ) );

		$spamRegexList = new spamRegexList( 'mediawiki', $context );
		$out = $context->getOutput();

		$spamRegexList->showPrevNext( $out );

		$html = $out->getHTML();

		$this->assertStringStartsWith( '<p><div class="mw-pager-navigation-bar">View (<span class="mw-prevlink">previous 50', $html );

		preg_match_all( '!<a.*?</a>!', $html, $m, PREG_PATTERN_ORDER );
		$links = $m[0];

		$nums = [ 20, 100, 250, 500 ];
		$i = 0;
		foreach ( $links as $a ) {
			$this->assertStringContainsString( 'Special:SpamRegex', $a );
			$this->assertStringContainsString( "limit=$nums[$i]&amp;offset=", $a );
			$this->assertStringContainsString( 'class="mw-numlink"', $a );
			$this->assertStringContainsString( ">$nums[$i]<", $a );
			$i += 1;
		}
	}
}

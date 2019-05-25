<?php

/**
 * @covers spamRegexList
 * @group Database
 */
class SpamRegexListTest extends MediaWikiTestCase {

	public function testShowPrevNext() {
		$context = new RequestContext();

		$context->setUser( $this->getTestUser( [ 'staff' ] )->getUser() );
		$context->setTitle( Title::newFromText( 'Special:SpamRegex' ) );

		$spamRegexList = new spamRegexList( 'mediawiki', $context );
		$out = $context->getOutput();

		$spamRegexList->showPrevNext( $out );

		$html = $out->getHTML();

		$this->assertStringStartsWith( '<p>View (previous 50 ', $html );

		preg_match_all( '!<a.*?</a>!', $html, $m, PREG_PATTERN_ORDER );
		$links = $m[0];

		$nums = [ 20, 50, 100, 250, 500 ];
		$i = 0;
		foreach ( $links as $a ) {
			$this->assertContains( 'Special:SpamRegex', $a );
			$this->assertContains( "limit=$nums[$i]&amp;offset=", $a );
			$this->assertContains( 'class="mw-numlink"', $a );
			$this->assertContains( ">$nums[$i]<", $a );
			$i += 1;
		}
	}
}

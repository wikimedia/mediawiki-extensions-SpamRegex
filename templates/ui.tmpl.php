<?php
/**
 * @file
 */

/**
 * HTML template for Special:SpamRegex
 *
 * @ingroup Templates
 */
// phpcs:ignore MediaWiki.Files.ClassMatchesFilename.NotMatch
class SpamRegexUITemplate extends QuickTemplate {
	public function execute() {
?>
<!-- s:<?php echo __FILE__ ?> -->
<form name="spamregex" method="post" action="<?php echo $this->data['action'] ?>">
	<table border="0">
		<tr>
			<td align="right"><?php echo wfMessage( 'spamregex-phrase-block' )->parse() ?></td>
			<td align="left">
				<input tabindex="1" name="wpBlockedPhrase" value="<?php echo $this->data['scBlockedPhrase'] ?>" size="75" />
			</td>
		</tr>
		<tr>
			<td align="right"><?php echo wfMessage( 'spamregex-reason' )->parse() ?></td>
			<td align="left">
				<input tabindex="1" name="wpBlockedReason" value="" size="75" />
			</td>
		</tr>
		<tr>
			<td align="right">&#160;</td>
			<td align="left">
				<input type="checkbox" tabindex="2" name="wpBlockedTextbox" id="wpBlockedTextbox" value="1" checked="checked" />
				<label for="wpBlockedTextbox"><?php echo wfMessage( 'spamregex-phrase-block-text' )->parse() ?></label>
			</td>
		</tr>
		<tr>
			<td align="right">&#160;</td>
			<td align="left">
				<input type="checkbox" tabindex="3" name="wpBlockedSummary" id="wpBlockedSummary" value="1" />
				<label for="wpBlockedSummary"><?php echo wfMessage( 'spamregex-phrase-block-summary' )->parse() ?></label>
			</td>
		</tr>
		<tr>
			<td align="right">&#160;</td>
			<td align="left">
				<input tabindex="4" name="wpSpamRegexBlockedSubmit" type="submit" value="<?php echo wfMessage( 'spamregex-block-submit' )->escaped() ?>" />
			</td>
		</tr>
	</table>
	<input type="hidden" name="wpEditToken" value="<?php echo $this->data['token'] ?>" />
</form>
<!-- e:<?php echo __FILE__ ?> -->
<?php
	} // execute()
} // template class

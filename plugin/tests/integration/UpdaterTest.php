<?php

use MDIA\Update\Updater;

class UpdaterTest extends WP_UnitTestCase {

	private array $requests = array();

	private function github_answers( $body, int $code = 200 ): void {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $body, $code ) {
				$this->requests[] = $url;
				return is_wp_error( $body ) ? $body : array(
					'response' => array( 'code' => $code, 'message' => 'x' ),
					'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
					'headers'  => array(),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
	}

	private function release( string $tag, ?string $asset_url = null, ?string $asset_name = null ): array {
		$version = ltrim( $tag, 'v' );
		return array(
			'tag_name'   => $tag,
			'draft'      => false,
			'prerelease' => false,
			'assets'     => array(
				array(
					'name'                 => $asset_name ?? "geo-tool-$version.zip",
					'browser_download_url' => $asset_url ?? "https://github.com/prodigioso-volcan/geo-tool/releases/download/$tag/geo-tool-$version.zip",
				),
			),
		);
	}

	private function check() {
		return apply_filters( 'update_plugins_github.com', false, array(), plugin_basename( MDIA_FILE ), array() );
	}

	public function test_plugin_declares_where_its_updates_come_from() {
		$data = get_file_data( MDIA_FILE, array( 'uri' => 'Update URI' ) );

		$this->assertSame( 'https://github.com/prodigioso-volcan/geo-tool', $data['uri'] );
	}

	public function test_latest_release_is_offered_with_its_zip() {
		$this->github_answers( $this->release( 'v9.4.2' ) );

		$update = $this->check();

		$this->assertSame( '9.4.2', $update['version'] );
		$this->assertSame( 'https://github.com/prodigioso-volcan/geo-tool/releases/download/v9.4.2/geo-tool-9.4.2.zip', $update['package'] );
		$this->assertSame( 'geo-tool', $update['slug'] );
		$this->assertSame( array( 'https://api.github.com/repos/prodigioso-volcan/geo-tool/releases/latest' ), $this->requests );
	}

	public function test_answer_is_cached_so_github_is_not_asked_on_every_check() {
		$this->github_answers( $this->release( 'v9.4.2' ) );
		$this->check();
		$this->check();

		$this->assertCount( 1, $this->requests );
	}

	public function test_other_plugins_are_left_alone() {
		$this->github_answers( $this->release( 'v9.4.2' ) );

		$this->assertFalse( apply_filters( 'update_plugins_github.com', false, array(), 'otro/otro.php', array() ) );
		$this->assertCount( 0, $this->requests );
	}

	public function unusable_releases(): array {
		return array(
			'zip hosted elsewhere'      => array( array( 'v9.4.2', 'https://evil.example/geo-tool-9.4.2.zip', null ) ),
			'zip from another repo'     => array( array( 'v9.4.2', 'https://github.com/someone/else/releases/download/v9.4.2/geo-tool-9.4.2.zip', null ) ),
			'unexpected file name'      => array( array( 'v9.4.2', 'https://github.com/prodigioso-volcan/geo-tool/releases/download/v9.4.2/source.zip', 'source.zip' ) ),
			'tag that is not a version' => array( array( 'latest', null, null ) ),
			'tag with injection'        => array( array( 'v9.4.2/../../x', null, null ) ),
		);
	}

	/**
	 * @dataProvider unusable_releases
	 */
	public function test_releases_that_do_not_look_right_are_ignored( array $release ) {
		$this->github_answers( $this->release( ...$release ) );

		$this->assertFalse( $this->check() );
	}

	public function test_drafts_and_prereleases_are_ignored() {
		$release               = $this->release( 'v9.4.2' );
		$release['prerelease'] = true;
		$this->github_answers( $release );

		$this->assertFalse( $this->check() );
	}

	public function test_github_being_down_or_answering_garbage_changes_nothing() {
		$this->github_answers( new WP_Error( 'http_request_failed', 'timeout' ) );
		$this->assertFalse( $this->check() );

		delete_transient( Updater::TRANSIENT );
		$this->github_answers( '<html>rate limited</html>', 403 );
		$this->assertFalse( $this->check() );
	}

	public function test_failures_are_cached_briefly_too() {
		$this->github_answers( new WP_Error( 'http_request_failed', 'timeout' ) );
		$this->check();
		$this->check();

		$this->assertCount( 1, $this->requests );
	}
}

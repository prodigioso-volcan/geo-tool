<?php
/**
 * Offers new versions from the plugin's public GitHub releases in the Plugins screen.
 *
 * WordPress asks this class through the `Update URI` header of the main file. The only
 * data sent to GitHub is the request itself: no site address, no keys, no usage data.
 *
 * @package RevisorAlt
 */

namespace MDIA\Update;

defined( 'ABSPATH' ) || exit;

final class Updater {

	public const REPOSITORY = 'prodigioso-volcan/revisor-alt';

	public const SLUG = 'revisor-alt';

	public const TRANSIENT = 'mdia_latest_release';

	public static function register(): void {
		add_filter( 'update_plugins_github.com', array( self::class, 'check' ), 10, 3 );
	}

	/**
	 * @param array|false $update      Update data from an earlier filter.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin file relative to the plugins directory.
	 * @return array|false WordPress compares the version itself and decides whether to offer it.
	 */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( MDIA_FILE ) !== $plugin_file ) {
			return $update;
		}

		$release = self::latest_release();
		if ( ! $release ) {
			return $update;
		}

		$home = 'https://github.com/' . self::REPOSITORY;

		return array(
			'id'           => $home,
			'slug'         => self::SLUG,
			'version'      => $release['version'],
			'url'          => $home,
			'package'      => $release['package'],
			'requires'     => MDIA_MIN_WP,
			'requires_php' => '8.1',
		);
	}

	/**
	 * @return array{version: string, package: string}|null Null when there is no usable release.
	 */
	private static function latest_release(): ?array {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return isset( $cached['version'], $cached['package'] ) ? $cached : null;
		}

		$release = self::fetch();

		// A failure is remembered too, for less time, so a GitHub outage does not slow every admin page.
		set_transient( self::TRANSIENT, $release ?? array(), $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );

		return $release;
	}

	private static function fetch(): ?array {
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return null;
		}

		$tag = (string) ( $data['tag_name'] ?? '' );
		if ( 1 !== preg_match( '/^v?(\d+\.\d+\.\d+)$/', $tag, $match ) ) {
			return null;
		}
		$version = $match[1];

		// The package must be exactly the zip this project publishes: right repository, tag and file name.
		$expected = sprintf( 'https://github.com/%1$s/releases/download/%2$s/%3$s-%4$s.zip', self::REPOSITORY, $tag, self::SLUG, $version );
		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			if ( is_array( $asset ) && ( $asset['browser_download_url'] ?? '' ) === $expected ) {
				return array(
					'version' => $version,
					'package' => $expected,
				);
			}
		}
		return null;
	}
}

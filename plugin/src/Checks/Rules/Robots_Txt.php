<?php
/**
 * Reads a robots.txt to see who is kept out of the whole site. Pure.
 *
 * @package GeoTool
 */

namespace MDIA\Checks\Rules;

final class Robots_Txt {

	/**
	 * AI crawlers and assistants a site normally wants to be readable by.
	 */
	public const AI_AGENTS = array( 'GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-SearchBot', 'PerplexityBot', 'Google-Extended' );

	public static function blocks_all( string $robots ): bool {
		return self::is_fully_blocked( self::groups( $robots ), '*' );
	}

	/**
	 * @param string[] $agents User agents to check.
	 * @return string[] The ones that cannot read any page.
	 */
	public static function blocked_agents( string $robots, array $agents ): array {
		$groups  = self::groups( $robots );
		$blocked = array();

		foreach ( $agents as $agent ) {
			$key = isset( $groups[ strtolower( $agent ) ] ) ? $agent : '*';
			if ( self::is_fully_blocked( $groups, $key ) ) {
				$blocked[] = $agent;
			}
		}
		return $blocked;
	}

	public static function has_sitemap( string $robots ): bool {
		return 1 === preg_match( '/^\s*sitemap\s*:\s*\S+/im', $robots );
	}

	/**
	 * A user agent is fully blocked when its group disallows "/" and allows nothing back.
	 */
	private static function is_fully_blocked( array $groups, string $agent ): bool {
		$rules = $groups[ strtolower( $agent ) ] ?? null;
		if ( null === $rules ) {
			return false;
		}
		return in_array( '/', $rules['disallow'], true ) && ! in_array( '/', $rules['allow'], true );
	}

	/**
	 * @return array<string, array{allow: string[], disallow: string[]}> Rules by lower-case user agent.
	 */
	private static function groups( string $robots ): array {
		$groups   = array();
		$current  = array();
		$in_rules = false;

		foreach ( preg_split( '/\R/', $robots ) as $line ) {
			$line = trim( (string) preg_replace( '/#.*$/', '', $line ) );
			if ( '' === $line || ! str_contains( $line, ':' ) ) {
				continue;
			}
			list( $field, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$field                 = strtolower( $field );

			if ( 'user-agent' === $field ) {
				if ( $in_rules ) {
					$current  = array();
					$in_rules = false;
				}
				$agent     = strtolower( $value );
				$current[] = $agent;
				$groups[ $agent ] ??= array(
					'allow'    => array(),
					'disallow' => array(),
				);
			} elseif ( in_array( $field, array( 'allow', 'disallow' ), true ) ) {
				$in_rules = true;
				if ( '' === $value ) {
					continue;
				}
				foreach ( $current as $agent ) {
					$groups[ $agent ][ $field ][] = $value;
				}
			}
		}
		return $groups;
	}
}

<?php
/**
 * REST API used by the admin screens. Namespace mdia/v1.
 *
 * @package GeoTool
 */

namespace MDIA\Rest;

use MDIA\AI\Budget;
use MDIA\AI\Generator;
use MDIA\AI\Image_Encoder;
use MDIA\AI\Prompt;
use MDIA\Audit\Auditor;
use MDIA\Data\Changes;
use MDIA\Data\Items;
use MDIA\Data\Usages;
use MDIA\Health\Status;
use MDIA\Queue\Scheduler;
use MDIA\Review\Actions;
use MDIA\Settings;

defined( 'ABSPATH' ) || exit;

final class Api {

	public const NAMESPACE = 'mdia/v1';

	public const STATUSES = array( 'ok', 'decorative', 'needs_generation', 'needs_review', 'applying', 'applied', 'ignored', 'error', 'unused' );

	private const ISSUES = array( 'missing', 'filename', 'generic', 'too_short', 'too_long', 'duplicate' );

	/**
	 * Largest number of generations enqueued by one request; the client asks again for the rest.
	 */
	private const GENERATION_CHUNK = 500;

	private const MAX_TEXT_LENGTH = 1000;

	private const ERROR_STATUS = array(
		'mdia_forbidden' => 403,
		'mdia_not_found' => 404,
		'mdia_busy'      => 409,
	);

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	public static function routes(): void {
		$review = static fn(): bool => current_user_can( 'mdia_review' );
		$manage = static fn(): bool => current_user_can( 'mdia_manage' );
		$id_arg = array(
			'id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => 'GET',
				'permission_callback' => $review,
				'callback'            => array( self::class, 'get_status' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/items',
			array(
				'methods'             => 'GET',
				'permission_callback' => $review,
				'callback'            => array( self::class, 'get_items' ),
				'args'                => array(
					'status'   => array(
						'type' => 'string',
						'enum' => self::STATUSES,
					),
					'issue'    => array(
						'type' => 'string',
						'enum' => self::ISSUES,
					),
					'search'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 20,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/items/approve',
			array(
				'methods'             => 'POST',
				'permission_callback' => $review,
				'callback'            => static fn( \WP_REST_Request $request ) => rest_ensure_response( Actions::approve_many( (array) $request['items'], get_current_user_id() ) ),
				'args'                => array(
					// Each entry carries the text the reviewer has on screen, so inline edits are what gets published.
					'items' => array(
						'type'     => 'array',
						'required' => true,
						'minItems' => 1,
						'maxItems' => 100,
						'items'    => array(
							'type'       => 'object',
							'properties' => array(
								'id'   => array(
									'type'     => 'integer',
									'minimum'  => 1,
									'required' => true,
								),
								'text' => array(
									'type'      => 'string',
									'maxLength' => self::MAX_TEXT_LENGTH,
								),
							),
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/items/(?P<id>\d+)/approve',
			array(
				'methods'             => 'POST',
				'permission_callback' => $review,
				'callback'            => static fn( \WP_REST_Request $request ) => self::item_result( (int) $request['id'], Actions::approve( (int) $request['id'], (string) $request['text'], get_current_user_id() ) ),
				'args'                => $id_arg + array(
					'text' => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => self::MAX_TEXT_LENGTH,
					),
				),
			)
		);

		foreach ( array(
			'decorative' => 'mark_decorative',
			'ignore'     => 'ignore',
			'regenerate' => 'regenerate',
			'undo'       => 'undo',
		) as $route => $method ) {
			register_rest_route(
				self::NAMESPACE,
				'/items/(?P<id>\d+)/' . $route,
				array(
					'methods'             => 'POST',
					'permission_callback' => $review,
					'callback'            => static fn( \WP_REST_Request $request ) => self::item_result( (int) $request['id'], Actions::$method( (int) $request['id'], get_current_user_id() ) ),
					'args'                => $id_arg,
				)
			);
		}

		register_rest_route(
			self::NAMESPACE,
			'/generation',
			array(
				array(
					'methods'             => 'POST',
					'permission_callback' => $manage,
					'callback'            => array( self::class, 'start_generation' ),
					'args'                => array(
						'limit' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => $manage,
					'callback'            => static fn() => rest_ensure_response( array( 'paused' => Scheduler::pause_generation() ) ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/audit',
			array(
				'methods'             => 'POST',
				'permission_callback' => $manage,
				'callback'            => static function () {
					Scheduler::start_full_audit();
					return rest_ensure_response( Scheduler::full_audit_state() );
				},
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $manage,
					'callback'            => static fn() => rest_ensure_response( Settings::all() ),
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $manage,
					'callback'            => static function ( \WP_REST_Request $request ) {
						Settings::update( array_intersect_key( $request->get_params(), Settings::defaults() ) );
						return rest_ensure_response( Settings::all() );
					},
					'args'                => array(
						'daily_limit'       => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 5000,
						),
						'auto_generate_new' => array( 'type' => 'boolean' ),
						'wizard_done'       => array( 'type' => 'boolean' ),
						'text_consent'      => array( 'type' => 'boolean' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/findings',
			array(
				'methods'             => 'GET',
				'permission_callback' => $review,
				'callback'            => array( self::class, 'get_findings' ),
				'args'                => array(
					'area'     => array(
						'type' => 'string',
						'enum' => array_keys( Status::AREAS ),
					),
					'fix'      => array(
						'type' => 'string',
						'enum' => array( 'ai', 'manual', 'setting' ),
					),
					'status'   => array(
						'type' => 'string',
						'enum' => array( 'pending', 'open', 'proposed', 'applied', 'waived', 'error' ),
					),
					'check_id' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 20,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/findings/approve',
			array(
				'methods'             => 'POST',
				'permission_callback' => $review,
				'callback'            => static fn( \WP_REST_Request $request ) => rest_ensure_response( \MDIA\Checks\Finding_Actions::approve_many( (array) $request['items'], get_current_user_id() ) ),
				'args'                => array(
					'items' => array(
						'type'     => 'array',
						'required' => true,
						'minItems' => 1,
						'maxItems' => 100,
						'items'    => array(
							'type'       => 'object',
							'properties' => array(
								'id'   => array(
									'type'     => 'integer',
									'minimum'  => 1,
									'required' => true,
								),
								'text' => array(
									'type'      => 'string',
									'maxLength' => self::MAX_TEXT_LENGTH,
								),
							),
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/findings/generation',
			array(
				'methods'             => 'POST',
				'permission_callback' => $manage,
				'callback'            => array( self::class, 'start_text_generation' ),
				'args'                => array(
					'area' => array(
						'type' => 'string',
						'enum' => array_keys( Status::AREAS ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/findings/acf-field',
			array(
				'methods'             => 'POST',
				'permission_callback' => $manage,
				'callback'            => static function ( \WP_REST_Request $request ) {
					$result = \MDIA\Checks\Finding_Actions::waive_acf_field( (string) $request['name'], get_current_user_id() );
					return is_wp_error( $result ) ? self::with_status( $result ) : rest_ensure_response( array( 'waived' => true ) );
				},
				'args'                => array(
					'name' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/findings/(?P<id>\\d+)/approve',
			array(
				'methods'             => 'POST',
				'permission_callback' => $review,
				'callback'            => static fn( \WP_REST_Request $request ) => self::finding_result( (int) $request['id'], \MDIA\Checks\Finding_Actions::approve( (int) $request['id'], (string) $request['text'], get_current_user_id() ) ),
				'args'                => $id_arg + array(
					'text' => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => self::MAX_TEXT_LENGTH,
					),
				),
			)
		);

		foreach ( array( 'waive', 'reopen', 'regenerate', 'undo', 'trash' ) as $action ) {
			register_rest_route(
				self::NAMESPACE,
				'/findings/(?P<id>\\d+)/' . $action,
				array(
					'methods'             => 'POST',
					'permission_callback' => $review,
					'callback'            => static fn( \WP_REST_Request $request ) => self::finding_result( (int) $request['id'], \MDIA\Checks\Finding_Actions::$action( (int) $request['id'], get_current_user_id() ) ),
					'args'                => $id_arg,
				)
			);
		}

		register_rest_route(
			self::NAMESPACE,
			'/wizard/requirements',
			array(
				'methods'             => 'GET',
				'permission_callback' => $manage,
				'callback'            => array( self::class, 'wizard_requirements' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/wizard/test',
			array(
				'methods'             => 'POST',
				'permission_callback' => $manage,
				'callback'            => array( self::class, 'wizard_test' ),
				'args'                => array(
					'attachment_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
			)
		);
	}

	public static function get_status() {
		// Cached for a minute; every write of the plugin clears it, so polling stays cheap and current.
		$status = Status::compute();

		if ( ! current_user_can( 'mdia_manage' ) ) {
			foreach ( $status['checks'] as &$check ) {
				if ( 'connector' === $check['id'] && 'failed' === $check['code'] ) {
					$check['detail'] = Generator::public_error();
				}
			}
			unset( $check );
		}
		return rest_ensure_response( $status );
	}

	public static function get_items( \WP_REST_Request $request ) {
		$per_page = (int) $request['per_page'];
		$result   = ( new Items() )->query(
			array(
				'status'   => $request['status'],
				'issue'    => $request['issue'],
				'search'   => $request['search'],
				'page'     => (int) $request['page'],
				'per_page' => $per_page,
			)
		);

		$response = rest_ensure_response( array_map( array( self::class, 'prepare_item' ), $result['items'] ) );
		$response->header( 'X-WP-Total', $result['total'] );
		$response->header( 'X-WP-TotalPages', (int) ceil( $result['total'] / $per_page ) );
		return $response;
	}

	public static function get_findings( \WP_REST_Request $request ) {
		$args = array(
			'page'     => (int) $request['page'],
			'per_page' => (int) $request['per_page'],
		);
		if ( $request['area'] ) {
			$args['target_type'] = Status::AREAS[ $request['area'] ];
		}
		if ( $request['status'] ) {
			$args['status'] = 'pending' === $request['status'] ? \MDIA\Data\Findings::PENDING : $request['status'];
		}

		$checks = \MDIA\Checks\Registry::all();
		if ( $request['check_id'] ) {
			$args['check_id'] = (string) $request['check_id'];
		} elseif ( $request['fix'] ) {
			$args['check_id'] = array_keys( array_filter( $checks, static fn( $check ): bool => $check->fix() === $request['fix'] ) );
			if ( ! $args['check_id'] ) {
				$args['check_id'] = array( '-' );
			}
		}

		$result   = ( new \MDIA\Data\Findings() )->query( $args );
		$response = rest_ensure_response( array_values( array_filter( array_map( array( self::class, 'prepare_finding' ), $result['items'] ) ) ) );
		$response->header( 'X-WP-Total', $result['total'] );
		$response->header( 'X-WP-TotalPages', (int) ceil( $result['total'] / $args['per_page'] ) );
		return $response;
	}

	/**
	 * Queues AI proposals for every open text finding, in server-sized chunks.
	 */
	public static function start_text_generation( \WP_REST_Request $request ) {
		$checks = array_keys( array_filter( \MDIA\Checks\Registry::all(), static fn( $check ): bool => 'ai' === $check->fix() ) );
		$args   = array(
			'check_id' => $checks ? $checks : array( '-' ),
			'status'   => array( 'open', 'error' ),
			'queued'   => false,
			'per_page' => self::GENERATION_CHUNK,
		);
		if ( $request['area'] ) {
			$args['target_type'] = Status::AREAS[ $request['area'] ];
		}

		Generator::clear_health();
		$findings = new \MDIA\Data\Findings();
		$queued   = Scheduler::enqueue_text_generation( wp_list_pluck( $findings->query( $args )['items'], 'id' ) );

		return rest_ensure_response(
			array(
				'queued'    => $queued,
				'remaining' => $queued ? $findings->query( $args )['total'] : 0,
			)
		);
	}

	public static function prepare_finding( array $row ): ?array {
		$check = \MDIA\Checks\Registry::get( $row['check_id'] );
		if ( ! $check ) {
			return null;
		}
		$target_id = (int) $row['target_id'];
		$manager   = current_user_can( 'mdia_manage' );
		// Nobody is shown the title or the texts of a page they could not open.
		if ( 'post' === $row['target_type'] && ! current_user_can( 'read_post', $target_id ) ) {
			return null;
		}

		return array(
			'id'             => (int) $row['id'],
			'check_id'       => $row['check_id'],
			'label'          => $check->label(),
			'why'            => $check->why(),
			'area'           => Status::area_of( $row['target_type'] ),
			'fix'            => $check->fix(),
			'severity'       => $row['severity'],
			'status'         => $row['status'],
			'queued'         => (bool) $row['queued'],
			'problem'        => \MDIA\Checks\Detail::text( $row['check_id'], (string) $row['detail'], (string) $row['current_value'] ),
			'target_type'    => $row['target_type'],
			'target_id'      => $target_id,
			'target_key'     => $row['target_key'],
			'target_label'   => $check->target_label( $target_id ),
			'current_value'  => (string) $row['current_value'],
			'proposed_value' => (string) $row['proposed_value'],
			'is_human'       => 'ai' === $check->fix() && $check->is_human_value( $target_id ),
			'limits'         => 'ai' === $check->fix() ? $check->limits( $target_id ) : null,
			'last_error'     => '' === (string) $row['last_error'] || $manager ? (string) $row['last_error'] : Generator::public_error(),
			'fix_url'        => $check->fix_url( $target_id ),
			'view_url'       => 'post' === $row['target_type'] ? (string) get_permalink( $target_id ) : '',
			'can_edit'       => $check->can_edit( get_current_user_id(), $target_id ),
			'can_waive'      => $check->can_edit( get_current_user_id(), $target_id ) && ( 'required' !== $row['severity'] || $manager ),
			'can_trash'      => \MDIA\Checks\Post\Test_Page::ID === $row['check_id'] && 'waived' !== $row['status'] && current_user_can( 'delete_post', $target_id ),
			'can_undo'       => 'applied' === $row['status'] && null !== $row['previous_value'] && \MDIA\Checks\Finding_Actions::is_untouched( $row, $check ),
		);
	}

	/**
	 * @param true|\WP_Error $result
	 */
	private static function finding_result( int $finding_id, $result ) {
		if ( is_wp_error( $result ) ) {
			return self::with_status( $result );
		}
		$row = ( new \MDIA\Data\Findings() )->get( $finding_id );
		// A finding that now passes is gone: say so instead of failing.
		return rest_ensure_response( $row ? self::prepare_finding( $row ) : array( 'id' => $finding_id, 'status' => 'resolved' ) );
	}

	private static function with_status( \WP_Error $error ): \WP_Error {
		$error->add_data( array( 'status' => self::ERROR_STATUS[ $error->get_error_code() ] ?? 400 ) );
		return $error;
	}

	public static function start_generation( \WP_REST_Request $request ) {
		$items = new Items();
		$limit = min( self::GENERATION_CHUNK, (int) ( $request['limit'] ?? self::GENERATION_CHUNK ) );

		// A person is asking to try again: a failure recorded earlier no longer blocks the queue.
		Generator::clear_health();

		return rest_ensure_response(
			array(
				'queued'    => Scheduler::enqueue_generation( $items->ids_to_generate( $limit ) ),
				'remaining' => $items->count_to_generate(),
			)
		);
	}

	public static function wizard_requirements() {
		global $wp_version;

		return rest_ensure_response(
			array(
				'wp_version'     => $wp_version,
				'wp_ok'          => version_compare( $wp_version, MDIA_MIN_WP, '>=' ),
				'provider'       => Generator::provider()->availability(),
				'connectors_url' => admin_url( 'options-connectors.php' ),
				'seo'            => array(
					'active'      => \MDIA\Seo\Seo::adapter()->is_active(),
					'name'        => \MDIA\Seo\Seo::adapter()->name(),
					'install_url' => admin_url( 'plugin-install.php?s=yoast+seo&tab=search&type=term' ),
				),
				'image_count'    => self::latest_image() ? array_sum( ( new Items() )->count_by_status() ) : 0,
			)
		);
	}

	/**
	 * Describes one image to prove the connector works. Stores nothing.
	 */
	public static function wizard_test( \WP_REST_Request $request ) {
		$attachment_id = (int) ( $request['attachment_id'] ?? 0 );
		$attachment_id = $attachment_id && Auditor::is_supported( $attachment_id ) ? $attachment_id : self::latest_image();
		if ( ! $attachment_id ) {
			return new \WP_Error( 'mdia_no_images', __( 'The media library has no images to test with.', 'geo-tool' ), array( 'status' => 400 ) );
		}

		$provider = Generator::provider();
		if ( empty( $provider->availability()['available'] ) ) {
			return new \WP_Error( 'mdia_no_provider', __( 'No AI provider that accepts images is connected.', 'geo-tool' ), array( 'status' => 400 ) );
		}

		$data_uri = Image_Encoder::encode( $attachment_id );
		if ( is_wp_error( $data_uri ) ) {
			$data_uri->add_data( array( 'status' => 400 ) );
			return $data_uri;
		}

		if ( ! Budget::consume() ) {
			return new \WP_Error( 'mdia_budget', __( 'The daily limit of AI requests has been reached.', 'geo-tool' ), array( 'status' => 429 ) );
		}

		$context = array(
			'filename' => wp_basename( (string) get_attached_file( $attachment_id ) ),
			'title'    => get_the_title( $attachment_id ),
		);
		$answer  = $provider->describe( $data_uri, Prompt::system( get_locale() ), Prompt::user( $context ) );
		if ( is_wp_error( $answer ) ) {
			Generator::set_health( false, 'failed', $answer->get_error_message() );
			return new \WP_Error( 'mdia_provider_error', $answer->get_error_message(), array( 'status' => 502 ) );
		}

		$text = Generator::usable_text( $provider, (string) $answer['text'] );
		if ( null === $text ) {
			return new \WP_Error( 'mdia_bad_response', __( 'The AI provider did not return a usable description.', 'geo-tool' ), array( 'status' => 502 ) );
		}
		Generator::set_health( true );

		return rest_ensure_response(
			array(
				'attachment_id' => $attachment_id,
				'thumbnail_url' => (string) wp_get_attachment_image_url( $attachment_id, 'medium' ),
				'text'          => $text,
				'provider'      => (string) $answer['provider'],
				'model'         => (string) $answer['model'],
			)
		);
	}

	public static function prepare_item( array $row ): array {
		$id         = (int) $row['attachment_id'];
		$kinds = array(
			\MDIA\Audit\Usage_Indexer::CONTENT => 0,
			\MDIA\Audit\Usage_Indexer::LIBRARY => 0,
			\MDIA\Audit\Usage_Indexer::FIXED   => 0,
			\MDIA\Audit\Usage_Indexer::BUILDER => 0,
		);
		foreach ( ( new Usages() )->for_attachment( $id ) as $usage ) {
			++$kinds[ isset( $kinds[ $usage['kind'] ] ) ? $usage['kind'] : \MDIA\Audit\Usage_Indexer::BUILDER ];
		}

		return array(
			'attachment_id'   => $id,
			'status'          => $row['status'],
			'issues'          => '' === $row['issues'] ? array() : explode( ',', $row['issues'] ),
			'library_alt'     => (string) $row['library_alt'],
			'proposed_alt'    => (string) $row['proposed_alt'],
			'last_error'      => '' === (string) $row['last_error'] || current_user_can( 'mdia_manage' ) ? (string) $row['last_error'] : Generator::public_error(),
			'queued'          => (bool) $row['queued'],
			'provider'        => (string) $row['provider'],
			'model'           => (string) $row['model'],
			'title'           => get_the_title( $id ),
			'filename'        => wp_basename( (string) get_attached_file( $id ) ),
			'thumbnail_url'   => (string) wp_get_attachment_image_url( $id, 'thumbnail' ),
			'edit_url'        => admin_url( 'post.php?post=' . $id . '&action=edit' ),
			'usage_count'     => $kinds['content'] + $kinds['library'] + $kinds['fixed'],
			'fixed_count'     => $kinds['fixed'],
			'unmanaged_count' => $kinds['builder'],
			'can_undo'        => in_array( $row['status'], array( 'applied', 'decorative' ), true ) && array() !== ( new Changes() )->latest_batch( $id ),
		);
	}

	/**
	 * @param true|\WP_Error $result
	 */
	private static function item_result( int $attachment_id, $result ) {
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => self::ERROR_STATUS[ $result->get_error_code() ] ?? 400 ) );
			return $result;
		}
		return rest_ensure_response( self::prepare_item( ( new Items() )->get( $attachment_id ) ) );
	}

	private static function latest_image(): int {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => Auditor::MIME_TYPES,
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'fields'         => 'ids',
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}
}

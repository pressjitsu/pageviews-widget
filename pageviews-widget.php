<?php
/**
 * Plugin Name: Pageviews Widget
 * Description: A widget for the Pageviews plugin
 * Plugin URI: https://github.com/pressjitsu/pageviews-widget/
 * Version: 0.8-beta
 * License: GPLv3 or later
 */

class Pageviews_Widget extends WP_Widget {
	public function __construct() {
		$args = array(
			'classname' => 'pageviews-widget',
			'description' => 'Display your most viewed content',
		);

		parent::__construct( 'pageviews-widget', 'Pageviews', $args );
	}

	public function widget( $args, $instance ) {
		global $post;

		$args = wp_parse_args( $args, array(
			'before_widget' => '',
			'after_widget' => '',
			'before_title' => '',
			'after_title' => '',
			'widget_id' => '',
		) );

		$cache_key = 'pageviews-widget-' . $this->id;
		$data = get_transient( $cache_key );

		if ( ! $data ) {
			$data = array(
				'updated' => time(),
				'content' => '',
			);

			$query = null;
			$after = 'week';

			if ( ! empty( $instance['range'] ) && array_key_exists( $instance['range'], self::get_ranges() ) ) {
				$range = self::get_ranges();
				$range = $range[ $instance['range'] ];
				$after = $range['after'];
			}

			$ids = get_posts( array(
				'post_type' => apply_filters( 'pageviews_widget_post_types', array( 'post' ) ),
				'post_status' => 'publish',
				'posts_per_page' => apply_filters( 'pageviews_widget_posts_per_page_search', 500 ),
				'date_query' => array(
					'after' => $after,
				),
				'fields' => 'ids',
				'cache_results' => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			) );

			if ( ! empty( $ids ) ) {
				$stats = array();
				$request = wp_remote_post( 'https://pv.pjtsu.com/v1/get', array(
					'headers' => array(
						'X-Account' => Pageviews::get_account_key(),
					),
					'body' => implode( ',', $ids ),
				) );

				if ( wp_remote_retrieve_response_code( $request ) == 200 )
					$stats = json_decode( wp_remote_retrieve_body( $request ), true );

				if ( is_array( $stats ) && ! empty( $stats ) ) {
					arsort( $stats );
					$query = new WP_Query( array(
						'post_type' => apply_filters( 'pageviews_widget_post_types', array( 'post' ) ),
						'post_status' => 'publish',
						'posts_per_page' => apply_filters( 'pageviews_widget_posts_per_page', $instance['limit'] ),
						'post__in' => array_keys( $stats ),
						'orderby' => 'post__in',
					) );
				}
			}

			if ( $query && $query->have_posts() ) {
				ob_start();
				while ( $query->have_posts() ) {
					$query->the_post();

					// Allow themes to override behavior.
					if ( has_action( 'pageviews_widget' ) ) {
						do_action( 'pageviews_widget', array(
							'post' => $post,
							'post_id' => $post->ID,
							'pageviews' => $stats[ $post->ID ],
						) );
						continue;
					}
					?>
					<li>
						<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
					</li>
					<?php
				}

				wp_reset_postdata();
				$content = ob_get_clean();

				if ( ! empty( $content ) ) {
					$content = '<ul>' . $content . '</ul>';
					$data['content'] = $content;
				}
			}

			// Cache all the things.
			set_transient( $cache_key, $data, HOUR_IN_SECONDS );
		}

		// Don't output this widget if we don't have any content.
		if ( empty( $data['content'] ) )
			return;

		echo $args['before_widget'];

		if ( ! empty( $instance['title'] ) ) {
			$instance['title'] = apply_filters( 'widget_title', $instance['title'] );
			echo $args['before_title'] . $instance['title'] . $args['after_title'];
		}

		echo $data['content'];

		echo $args['after_widget'];
	}

	public function form( $instance ) {
		$title = ! empty( $instance['title'] ) ? $instance['title'] : 'Top Posts this Week';
		$range = ! empty( $instance['range'] ) ? $instance['range'] : 'week';
		$limit = ! empty( $instance['limit'] ) ? $instance['limit'] : 10;
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
				<?php _e( esc_attr( 'Title:' ) ); ?>
			</label>
			<input class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
				type="text" value="<?php echo esc_attr( $title ); ?>"
			>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'range' ) ); ?>">
				<?php _e( esc_attr( 'Range:' ) ); ?>
			</label>
			<select
				id="<?php echo esc_attr( $this->get_field_id( 'range' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'range' ) ); ?>">

				<?php foreach ( self::get_ranges() as $key => $range_data ) : ?>
				<option <?php selected( $key, $range ); ?> value="<?php echo esc_attr( $key ); ?>">
					<?php echo esc_html( $range_data['label'] ); ?>
				</option>
				<?php endforeach; ?>

			</select>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'limit' ) ); ?>">
				<?php _e( esc_attr( 'Limit:' ) ); ?>
			</label>
			<input type="number" min="1" max="50"
				id="<?php echo esc_attr( $this->get_field_id( 'limit' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'limit' ) ); ?>"
				value="<?php echo esc_attr( $limit ); ?>">
		</p>
		<?php
	}

	public function update( $new, $old ) {
		$instance = array();
		$instance['title'] = ( ! empty( $new['title'] ) ) ? strip_tags( $new['title'] ) : '';
		$instance['range'] = ( ! empty( $new['range'] ) && array_key_exists( $new['range'], self::get_ranges() ) ) ? $new['range'] : 'week';
		$instance['limit'] = ( ! empty( $new['limit'] ) ) ? absint( $new['limit'] ) : 10;

		$cache_key = 'pageviews-widget-' . $this->id;
		delete_transient( $cache_key );

		return $instance;
	}

	public static function get_ranges() {
		return array(
			'today' => array(
				'label' => 'Today',
				'after' => '1 day ago',
			),
			'week' => array(
				'label' => 'This week',
				'after' => '1 week ago',
			),
			'month' => array(
				'label' => 'This month',
				'after' => '1 month ago',
			),
			'90-days' => array(
				'label' => '90 days',
				'after' => '90 days ago',
			),
			'180-days' => array(
				'label' => '180 days',
				'after' => '180 days ago',
			),
			'365-days' => array(
				'label' => '365 days',
				'after' => '365 days ago',
			),
		);
	}

	public static function register() {
		add_action( 'widgets_init', array( __CLASS__, 'widgets_init' ) );
	}

	public static function widgets_init() {
		if ( ! class_exists( 'Pageviews' ) )
			return;

		register_widget( __CLASS__ );
	}
}

Pageviews_Widget::register();

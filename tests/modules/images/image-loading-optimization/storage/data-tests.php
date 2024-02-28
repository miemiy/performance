<?php
/**
 * Tests for image-loading-optimization module storage/data.php.
 *
 * @package performance-lab
 * @group   image-loading-optimization
 *
 * @noinspection PhpUnhandledExceptionInspection
 */

class ILO_Storage_Data_Tests extends WP_UnitTestCase {

	/**
	 * @var string
	 */
	private $original_request_uri;

	public function set_up() {
		$this->original_request_uri = $_SERVER['REQUEST_URI'];
		parent::set_up();
	}

	public function tear_down() {
		$_SERVER['REQUEST_URI'] = $this->original_request_uri;
		unset( $GLOBALS['wp_customize'] );
		parent::tear_down();
	}

	/**
	 * Test ilo_get_url_metric_freshness_ttl().
	 *
	 * @covers ::ilo_get_url_metric_freshness_ttl
	 */
	public function test_ilo_get_url_metric_freshness_ttl() {
		$this->assertSame( DAY_IN_SECONDS, ilo_get_url_metric_freshness_ttl() );

		add_filter(
			'ilo_url_metric_freshness_ttl',
			static function (): int {
				return HOUR_IN_SECONDS;
			}
		);

		$this->assertSame( HOUR_IN_SECONDS, ilo_get_url_metric_freshness_ttl() );
	}

	/**
	 * Data provider.
	 *
	 * @return array
	 */
	public function data_provider_test_ilo_get_normalized_query_vars(): array {
		return array(
			'homepage'     => array(
				'set_up' => function (): array {
					$this->go_to( home_url( '/' ) );
					return array();
				},
			),
			'post'         => array(
				'set_up' => function (): array {
					$post_id = self::factory()->post->create();
					$this->go_to( get_permalink( $post_id ) );
					return array( 'p' => (string) $post_id );
				},
			),
			'date-archive' => array(
				'set_up' => function (): array {
					$post_id = self::factory()->post->create();
					$date = get_post_datetime( $post_id );

					$this->go_to(
						add_query_arg(
							array(
								'day'      => $date->format( 'j' ),
								'year'     => $date->format( 'Y' ),
								'monthnum' => $date->format( 'm' ),
								'bogus'    => 'ignore me',
							),
							home_url()
						)
					);
					return array(
						'year'     => $date->format( 'Y' ),
						'monthnum' => $date->format( 'm' ),
						'day'      => $date->format( 'j' ),
					);
				},
			),
			'404'          => array(
				'set_up' => function () {
					$this->go_to( home_url( '/?p=1000000' ) );
					return array( 'error' => 404 );
				},
			),
			'logged-in'    => array(
				'set_up' => function () {
					wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
					$this->go_to( home_url( '/' ) );
					return array( 'user_logged_in' => true );
				},
			),
		);
	}

	/**
	 * Test ilo_get_normalized_query_vars().
	 *
	 * @covers ::ilo_get_normalized_query_vars
	 *
	 * @dataProvider data_provider_test_ilo_get_normalized_query_vars
	 */
	public function test_ilo_get_normalized_query_vars( Closure $set_up ) {
		$expected = $set_up();
		$this->assertSame( $expected, ilo_get_normalized_query_vars() );
	}

	/**
	 * Test ilo_get_url_metrics_slug().
	 *
	 * @covers ::ilo_get_url_metrics_slug
	 */
	public function test_ilo_get_url_metrics_slug() {
		$first  = ilo_get_url_metrics_slug( array() );
		$second = ilo_get_url_metrics_slug( array( 'p' => 1 ) );
		$this->assertNotEquals( $second, $first );
		foreach ( array( $first, $second ) as $slug ) {
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $slug );
		}
	}

	/**
	 * Test ilo_get_url_metrics_storage_nonce().
	 *
	 * @covers ::ilo_get_url_metrics_storage_nonce
	 * @covers ::ilo_verify_url_metrics_storage_nonce
	 */
	public function test_ilo_get_url_metrics_storage_nonce_and_ilo_verify_url_metrics_storage_nonce() {
		$user_id = self::factory()->user->create();

		$nonce_life_actions = array();
		add_filter(
			'nonce_life',
			static function ( int $life, string $action ) use ( &$nonce_life_actions ): int {
				$nonce_life_actions[] = $action;
				return $life;
			},
			10,
			2
		);

		// Create first nonce for unauthenticated user.
		$slug   = ilo_get_url_metrics_slug( array() );
		$nonce1 = ilo_get_url_metrics_storage_nonce( $slug );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{10}$/', $nonce1 );
		$this->assertTrue( ilo_verify_url_metrics_storage_nonce( $nonce1, $slug ) );
		$this->assertCount( 2, $nonce_life_actions );

		// Create second nonce for unauthenticated user.
		$nonce2 = ilo_get_url_metrics_storage_nonce( $slug );
		$this->assertSame( $nonce1, $nonce2 );
		$this->assertCount( 3, $nonce_life_actions );

		// Create third nonce, this time for authenticated user.
		wp_set_current_user( $user_id );
		$nonce3 = ilo_get_url_metrics_storage_nonce( $slug );
		$this->assertNotEquals( $nonce3, $nonce2 );
		$this->assertFalse( ilo_verify_url_metrics_storage_nonce( $nonce1, $slug ) );
		$this->assertTrue( ilo_verify_url_metrics_storage_nonce( $nonce3, $slug ) );
		$this->assertCount( 6, $nonce_life_actions );

		foreach ( $nonce_life_actions as $nonce_life_action ) {
			$this->assertSame( "store_url_metrics:{$slug}", $nonce_life_action );
		}
	}

	public function data_provider_sample_size_and_breakpoints(): array {
		return array(
			'3 sample size and 2 breakpoints' => array(
				'sample_size'     => 3,
				'breakpoints'     => array( 480, 782 ),
				'viewport_widths' => array( 400, 600, 800 ),
			),
			'1 sample size and 1 breakpoint'  => array(
				'sample_size'     => 1,
				'breakpoints'     => array( 480 ),
				'viewport_widths' => array( 400, 800 ),
			),
		);
	}

	/**
	 * Test ilo_unshift_url_metrics().
	 *
	 * @covers ILO_Grouped_URL_Metrics::add
	 *
	 * @dataProvider data_provider_sample_size_and_breakpoints
	 */
	public function test_ilo_unshift_url_metrics( int $sample_size, array $breakpoints, array $viewport_widths ) {
		$grouped_url_metrics = new ILO_Grouped_URL_Metrics( array(), $breakpoints, $sample_size, HOUR_IN_SECONDS );

		// Over-populate the sample size for the breakpoints by a dozen.
		foreach ( $viewport_widths as $viewport_width ) {
			for ( $i = 0; $i < $sample_size + 12; $i++ ) {
				$grouped_url_metrics->add( $this->get_validated_url_metric( $viewport_width ) );
			}
		}
		$max_possible_url_metrics_count = $sample_size * ( count( $breakpoints ) + 1 );
		$this->assertCount(
			$max_possible_url_metrics_count,
			$grouped_url_metrics->flatten(),
			sprintf( 'Expected there to be exactly sample size (%d) times the number of breakpoint groups (which is %d + 1)', $sample_size, count( $breakpoints ) )
		);
	}

	/**
	 * Test that ilo_unshift_url_metrics() pushes out old metrics.
	 *
	 * @covers ILO_Grouped_URL_Metrics::add
	 *
	 * @dataProvider data_provider_sample_size_and_breakpoints
	 * @throws Exception When a parse error happens.
	 */
	public function test_ilo_unshift_url_metrics_pushes_out_old_metrics( int $sample_size, array $breakpoints, array $viewport_widths ) {
		$old_timestamp = microtime( true ) - ( HOUR_IN_SECONDS + 1 );

		$grouped_url_metrics = new ILO_Grouped_URL_Metrics( array(), $breakpoints, $sample_size, HOUR_IN_SECONDS );

		// Populate the groups with stale URL metrics.
		foreach ( $viewport_widths as $viewport_width ) {
			for ( $i = 0; $i < $sample_size; $i++ ) {
				$grouped_url_metrics->add(
					new ILO_URL_Metric(
						array_merge(
							$this->get_validated_url_metric( $viewport_width )->jsonSerialize(),
							array(
								'timestamp' => $old_timestamp,
							)
						)
					)
				);
			}
		}

		// Try adding one URL metric for each breakpoint group.
		foreach ( $viewport_widths as $viewport_width ) {
			$grouped_url_metrics->add( $this->get_validated_url_metric( $viewport_width ) );
		}

		$max_possible_url_metrics_count = $sample_size * ( count( $breakpoints ) + 1 );
		$this->assertCount(
			$max_possible_url_metrics_count,
			$grouped_url_metrics->flatten(),
			'Expected the total count of URL metrics to not exceed the multiple of the sample size.'
		);
		$new_count = 0;
		foreach ( $grouped_url_metrics->flatten() as $url_metric ) {
			if ( $url_metric->get_timestamp() > $old_timestamp ) {
				++$new_count;
			}
		}
		$this->assertGreaterThan( 0, $new_count, 'Expected there to be at least one new URL metric.' );
		$this->assertSame( count( $viewport_widths ), $new_count, 'Expected the new URL metrics to all have been added.' );
	}

	/**
	 * Test ilo_get_breakpoint_max_widths().
	 *
	 * @covers ::ilo_get_breakpoint_max_widths
	 */
	public function test_ilo_get_breakpoint_max_widths() {
		$this->assertSame(
			array( 480, 600, 782 ),
			ilo_get_breakpoint_max_widths()
		);

		$filtered_breakpoints = array( 2000, 500, '1000', 3000 );

		add_filter(
			'ilo_breakpoint_max_widths',
			static function () use ( $filtered_breakpoints ) {
				return $filtered_breakpoints;
			}
		);

		$filtered_breakpoints = array_map( 'intval', $filtered_breakpoints );
		sort( $filtered_breakpoints );
		$this->assertSame( $filtered_breakpoints, ilo_get_breakpoint_max_widths() );
	}

	/**
	 * Test ilo_get_url_metrics_breakpoint_sample_size().
	 *
	 * @covers ::ilo_get_url_metrics_breakpoint_sample_size
	 */
	public function test_ilo_get_url_metrics_breakpoint_sample_size() {
		$this->assertSame( 3, ilo_get_url_metrics_breakpoint_sample_size() );

		add_filter(
			'ilo_url_metrics_breakpoint_sample_size',
			static function () {
				return '1';
			}
		);

		$this->assertSame( 1, ilo_get_url_metrics_breakpoint_sample_size() );
	}

	public function data_provider_test_ilo_group_url_metrics_by_breakpoint(): array {
		return array(
			'2-breakpoints-and-3-viewport-widths' => array(
				'breakpoints'     => array( 480, 640 ),
				'viewport_widths' => array( 400, 480, 800 ),
			),
			'1-breakpoint-and-4-viewport-widths'  => array(
				'breakpoints'     => array( 480 ),
				'viewport_widths' => array( 400, 600, 800, 1000 ),
			),
		);
	}

	/**
	 * Test ilo_group_url_metrics_by_breakpoint().
	 *
	 * @covers ILO_Grouped_URL_Metrics::get_groups
	 * @covers ILO_Grouped_URL_Metrics::get_minimum_viewport_widths
	 *
	 * @dataProvider data_provider_test_ilo_group_url_metrics_by_breakpoint
	 */
	public function test_ilo_group_url_metrics_by_breakpoint( array $breakpoints, array $viewport_widths ) {
		$url_metrics = array_map(
			function ( $viewport_width ) {
				return $this->get_validated_url_metric( $viewport_width );
			},
			$viewport_widths
		);

		$grouped_url_metrics = new ILO_Grouped_URL_Metrics( $url_metrics, $breakpoints, 3, HOUR_IN_SECONDS );

		$this->assertCount( count( $breakpoints ) + 1, $grouped_url_metrics->get_groups(), 'Expected number of breakpoint groups to always be one greater than the number of breakpoints.' );
		$minimum_viewport_widths = $grouped_url_metrics->get_minimum_viewport_widths();
		$this->assertSame( array_keys( $grouped_url_metrics->get_groups() ), $minimum_viewport_widths );
		$this->assertSame( 0, array_shift( $minimum_viewport_widths ), 'Expected the first minimum viewport width to always be zero.' );
		foreach ( $breakpoints as $breakpoint ) {
			$this->assertSame( $breakpoint + 1, array_shift( $minimum_viewport_widths ) );
		}

		$minimum_viewport_widths = $grouped_url_metrics->get_minimum_viewport_widths();
		for ( $i = 0, $len = count( $minimum_viewport_widths ); $i < $len; $i++ ) {
			$minimum_viewport_width = $minimum_viewport_widths[ $i ];
			$maximum_viewport_width = $minimum_viewport_widths[ $i + 1 ] ?? null;
			if ( 0 === $i ) {
				$this->assertSame( 0, $minimum_viewport_width );
			} else {
				$this->assertGreaterThan( 0, $minimum_viewport_width );
			}
			if ( isset( $maximum_viewport_width ) ) {
				$this->assertLessThan( $maximum_viewport_width, $minimum_viewport_width );
			}

			foreach ( $grouped_url_metrics->get_groups()[ $minimum_viewport_width ] as $url_metric ) {
				$this->assertGreaterThanOrEqual( $minimum_viewport_width, $url_metric->get_viewport()['width'] );
				if ( isset( $maximum_viewport_width ) ) {
					$this->assertLessThanOrEqual( $maximum_viewport_width, $url_metric->get_viewport()['width'] );
				}
			}
		}
	}

	public function data_provider_test_ilo_get_lcp_elements_by_minimum_viewport_widths(): array {
		return array(
			'common_lcp_element_across_breakpoints'    => array(
				'breakpoints'                 => array( 600, 800 ),
				'url_metrics'                 => array(
					// 0.
					$this->get_validated_url_metric( 400, array( 'HTML', 'BODY', 'FIGURE', 'IMG' ) ),
					$this->get_validated_url_metric( 500, array( 'HTML', 'BODY', 'DIV', 'IMG' ) ), // Ignored since less common than the other two.
					$this->get_validated_url_metric( 599, array( 'HTML', 'BODY', 'FIGURE', 'IMG' ) ),
					// 600.
					$this->get_validated_url_metric( 600, array( 'HTML', 'BODY', 'FIGURE', 'IMG' ) ),
					$this->get_validated_url_metric( 700, array( 'HTML', 'BODY', 'FIGURE', 'IMG' ) ),
					// 800.
					$this->get_validated_url_metric( 900, array( 'HTML', 'BODY', 'FIGURE', 'IMG' ) ),
				),
				'expected_lcp_element_xpaths' => array(
					0 => $this->get_xpath( 'HTML', 'BODY', 'FIGURE', 'IMG' ),
				),
			),
			'different_lcp_elements_across_breakpoint' => array(
				'breakpoints'                 => array( 600 ),
				'url_metrics'                 => array(
					// 0.
					$this->get_validated_url_metric( 400, array( 'HTML', 'BODY', 'FIGURE', 'IMG' ) ),
					$this->get_validated_url_metric( 500, array( 'HTML', 'BODY', 'DIV', 'IMG' ) ), // Ignored since less common than the other two.
					$this->get_validated_url_metric( 600, array( 'HTML', 'BODY', 'FIGURE', 'IMG' ) ),
					// 600.
					$this->get_validated_url_metric( 800, array( 'HTML', 'BODY', 'MAIN', 'IMG' ) ),
					$this->get_validated_url_metric( 900, array( 'HTML', 'BODY', 'MAIN', 'IMG' ) ),
				),
				'expected_lcp_element_xpaths' => array(
					0   => $this->get_xpath( 'HTML', 'BODY', 'FIGURE', 'IMG' ),
					601 => $this->get_xpath( 'HTML', 'BODY', 'MAIN', 'IMG' ),
				),
			),
			'same_lcp_element_across_non_consecutive_breakpoints' => array(
				'breakpoints'                 => array( 400, 600 ),
				'url_metrics'                 => array(
					// 0.
					$this->get_validated_url_metric( 300, array( 'HTML', 'BODY', 'MAIN', 'IMG' ) ),
					// 400.
					$this->get_validated_url_metric( 500, array( 'HTML', 'BODY', 'HEADER', 'IMG' ), false ),
					// 600.
					$this->get_validated_url_metric( 800, array( 'HTML', 'BODY', 'MAIN', 'IMG' ) ),
					$this->get_validated_url_metric( 900, array( 'HTML', 'BODY', 'MAIN', 'IMG' ) ),
				),
				'expected_lcp_element_xpaths' => array(
					0   => $this->get_xpath( 'HTML', 'BODY', 'MAIN', 'IMG' ),
					401 => false, // The (image) element is either not visible at this breakpoint or it is not LCP element.
					601 => $this->get_xpath( 'HTML', 'BODY', 'MAIN', 'IMG' ),
				),
			),
			'no_lcp_image_elements'                    => array(
				'breakpoints'                 => array( 600 ),
				'url_metrics'                 => array(
					// 0.
					$this->get_validated_url_metric( 300, array( 'HTML', 'BODY', 'IMG' ), false ),
					// 600.
					$this->get_validated_url_metric( 700, array( 'HTML', 'BODY', 'IMG' ), false ),
				),
				'expected_lcp_element_xpaths' => array(
					0 => false,
				),
			),
		);
	}

	/**
	 * Test ilo_get_lcp_elements_by_minimum_viewport_widths().
	 *
	 * @covers ILO_Grouped_URL_Metrics::get_lcp_elements_by_minimum_viewport_widths
	 * @dataProvider data_provider_test_ilo_get_lcp_elements_by_minimum_viewport_widths
	 */
	public function test_ilo_get_lcp_elements_by_minimum_viewport_widths( array $breakpoints, array $url_metrics, array $expected_lcp_element_xpaths ) {
		$grouped_url_metrics = new ILO_Grouped_URL_Metrics( $url_metrics, $breakpoints, 10, HOUR_IN_SECONDS );

		$lcp_elements_by_minimum_viewport_widths = $grouped_url_metrics->get_lcp_elements_by_minimum_viewport_widths();

		$lcp_element_xpaths_by_minimum_viewport_widths = array();
		foreach ( $lcp_elements_by_minimum_viewport_widths as $minimum_viewport_width => $lcp_element ) {
			$this->assertTrue( is_array( $lcp_element ) || false === $lcp_element );
			if ( is_array( $lcp_element ) ) {
				$this->assertTrue( $lcp_element['isLCP'] );
				$this->assertTrue( $lcp_element['isLCPCandidate'] );
				$this->assertIsString( $lcp_element['xpath'] );
				$this->assertIsNumeric( $lcp_element['intersectionRatio'] );
				$lcp_element_xpaths_by_minimum_viewport_widths[ $minimum_viewport_width ] = $lcp_element['xpath'];
			} else {
				$lcp_element_xpaths_by_minimum_viewport_widths[ $minimum_viewport_width ] = false;
			}
		}

		$this->assertSame( $expected_lcp_element_xpaths, $lcp_element_xpaths_by_minimum_viewport_widths );
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public function data_provider_test_ilo_get_needed_minimum_viewport_widths(): array {
		$current_time = microtime( true );

		$none_needed_data = array(
			'url_metrics'   => ( function () use ( $current_time ): array {
				return array_merge(
					array_fill(
						0,
						3,
						new ILO_URL_Metric(
							array_merge(
								$this->get_validated_url_metric( 400 )->jsonSerialize(),
								array( 'timestamp' => $current_time )
							)
						)
					),
					array_fill(
						0,
						3,
						new ILO_URL_Metric(
							array_merge(
								$this->get_validated_url_metric( 600 )->jsonSerialize(),
								array( 'timestamp' => $current_time )
							)
						)
					)
				);
			} )(),
			'current_time'  => $current_time,
			'breakpoints'   => array( 480 ),
			'sample_size'   => 3,
			'freshness_ttl' => HOUR_IN_SECONDS,
		);

		return array(
			'none-needed'            => array_merge(
				$none_needed_data,
				array(
					'expected' => array(
						array( 0, false ),
						array( 481, false ),
					),
				)
			),

			'not-enough-url-metrics' => array_merge(
				$none_needed_data,
				array(
					'sample_size' => $none_needed_data['sample_size'] + 1,
				),
				array(
					'expected' => array(
						array( 0, true ),
						array( 481, true ),
					),
				)
			),

			'url-metric-too-old'     => array_merge(
				( static function ( $data ): array {
					$url_metrics_data = $data['url_metrics'][0]->jsonSerialize();
					$url_metrics_data['timestamp'] -= $data['freshness_ttl'] + 1;
					$data['url_metrics'][0] = new ILO_URL_Metric( $url_metrics_data );
					return $data;
				} )( $none_needed_data ),
				array(
					'expected' => array(
						array( 0, true ),
						array( 481, false ),
					),
				)
			),
		);
	}

	/**
	 * Test ilo_get_needed_minimum_viewport_widths().
	 *
	 * @covers ILO_Grouped_URL_Metrics::get_needed_minimum_viewport_widths
	 *
	 * @dataProvider data_provider_test_ilo_get_needed_minimum_viewport_widths
	 */
	public function test_ilo_get_needed_minimum_viewport_widths( array $url_metrics, float $current_time, array $breakpoints, int $sample_size, int $freshness_ttl, array $expected ) {
		$grouped_url_metrics = new ILO_Grouped_URL_Metrics( $url_metrics, $breakpoints, $sample_size, $freshness_ttl );
		$this->assertSame(
			$expected,
			$grouped_url_metrics->get_needed_minimum_viewport_widths()
		);
	}

	/**
	 * Gets a validated URL metric for testing.
	 *
	 * @param int      $viewport_width Viewport width.
	 * @param string[] $breadcrumbs    Breadcrumb tags.
	 * @param bool     $is_lcp         Whether LCP.
	 *
	 * @return ILO_URL_Metric Validated URL metric.
	 * @throws Exception From ILO_URL_Metric if there is a parse error, but there won't be.
	 */
	private function get_validated_url_metric( int $viewport_width = 480, array $breadcrumbs = array( 'HTML', 'BODY', 'IMG' ), bool $is_lcp = true ): ILO_URL_Metric {
		$data = array(
			'viewport'  => array(
				'width'  => $viewport_width,
				'height' => 640,
			),
			'timestamp' => microtime( true ),
			'elements'  => array(
				array(
					'isLCP'             => $is_lcp,
					'isLCPCandidate'    => $is_lcp,
					'xpath'             => $this->get_xpath( ...$breadcrumbs ),
					'intersectionRatio' => 1,
				),
			),
		);
		return new ILO_URL_Metric( $data );
	}

	/**
	 * Gets sample XPath.
	 *
	 * @param string ...$breadcrumbs List of tags.
	 * @return string XPath.
	 */
	private function get_xpath( ...$breadcrumbs ): string {
		return implode(
			'',
			array_map(
				static function ( $tag ) {
					return sprintf( '/*[0][self::%s]', strtoupper( $tag ) );
				},
				$breadcrumbs
			)
		);
	}
}

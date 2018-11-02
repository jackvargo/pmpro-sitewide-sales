<?php

namespace PMPro_Sitewide_Sales\includes\classes;

defined( 'ABSPATH' ) || die( 'File cannot be accessed directly' );

class PMPro_SWS_Reports {

	/**
	 * Adds actions for class
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'assign_pmpro_sws_reports' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_reports_js' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_tracking_js' ) );
		add_action( 'wp_ajax_pmpro_sws_ajax_reporting', array( __CLASS__, 'ajax_reporting' ) );
		add_action( 'wp_ajax_pmpro_sws_ajax_tracking', array( __CLASS__, 'ajax_tracking' ) );
		add_action( 'wp_ajax_nopriv_pmpro_sws_ajax_tracking', array( __CLASS__, 'ajax_tracking' ) );
	}

	/**
	 * Adds SWS Report to PMPro Reports
	 */
	public static function assign_pmpro_sws_reports() {
		global $pmpro_reports;
		// Functions called by adding this report are below the class.
		$pmpro_reports['pmpro_sws_reports'] = __( 'Sitewide Sales', 'pmpro-sitewide-sales' );
		return $pmpro_reports;
	}

	/**
	 * Gets the statistics for a Sitewide Sale
	 *
	 * @param  int $sitewide_sale_id ID of sale to get stats for, null is current active sale
	 * @return String                HTML table
	 */
	public static function get_stats_for_sale( $sitewide_sale_id = null ) {
		global $wpdb;

		// If no sale passed in, first check for the active sale.
		if ( empty( $sitewide_sale_id ) ) {
			$options = PMPro_SWS_Settings::get_options();
			$sitewide_sale_id = $options['active_sitewide_sale_id'];
		}

		// Still no sale, grab the first one we find.
		if ( empty( $sitewide_sale_id ) ) {
			$sitewide_sales = get_posts(
				[
					'post_type' => 'pmpro_sitewide_sale',
					'post_status' => 'publish',
					'numberposts' => 1,
					'orderby' => 'ID',
					'order' => 'DESC',
				]
			);
			$sitewide_sale_id = $sitewide_sales[0]->ID;
		}

		// check if discount_code_id is set.
		$stats = get_option( 'pmpro_sws_' . $sitewide_sale_id . '_tracking' );
		if ( empty( $reports ) ) {
			$stats = array(
				'banner_impressions'   => 0,
				'landing_page_visits'  => 0,
				'landing_page_after_banner'         => 0,
				'checkout_conversions_with_code'    => 0,
				'checkout_conversions_without_code' => 0,
			);
			update_option( 'pmpro_sws_' . $sitewide_sale_id . '_tracking', $stats, 'no' );
		}

		$stats['start_date'] = get_post_meta( $sitewide_sale_id, 'pmpro_sws_start_date', true );
		$stats['end_date'] = get_post_meta( $sitewide_sale_id, 'pmpro_sws_end_date', true );

		$landing_page_id = get_post_meta( $sitewide_sale_id, 'pmpro_sws_landing_page_post_id', true );
		if ( ! empty( $landing_page_id ) ) {
			$stats['landing_page'] = get_post( $landing_page_id );
			$stats['landing_page_url'] = get_permalink( $landing_page_id );
			$stats['landing_page_title'] = $stats['landing_page']->post_title;
		} else {
			$stats['landing_page'] = null;
			$stats['landing_page_url'] = '#';
			$stats['landing_page_title'] = esc_html__( 'N/A', 'pmpro-sitewide-sales' );
		}

		$discount_code_id = get_post_meta( $sitewide_sale_id, 'pmpro_sws_discount_code_id', true );
		if ( ! empty( $code_id ) ) {
			$stats['discount_code'] = $wpdb->get_var( $wpdb->prepare( "SELECT code FROM $wpdb->pmpro_discount_codes WHERE id=%d LIMIT 1", $discount_code_id ) );
		} else {
			$stats['discount_code'] = esc_html__( 'N/A', 'pmpro-sitewide-sales' );
		}

		$stats['new_rev_with_code'] = '200.00';
		$stats['new_rev_without_code'] = '100.00';
		$stats['old_rev'] = '50.00';

		return $stats;
	}

	/**
	 * Used to calculate percentge-based stats about sale
	 *
	 * @param  int $num   numerator of division.
	 * @param  int $denom denominator of division.
	 * @return int        percentage
	 */
	public static function divide_into_percent( $num, $denom ) {
		if ( $denom <= 0 ) {
			if ( $num <= 0 ) {
				return 0;
			}
			return 100; // 100%
		}
		return ( $num / $denom ) * 100;
	}

	/**
	 * Enqueues js for switching sale being displayed in reports
	 */
	public static function enqueue_reports_js() {
		if ( isset( $_REQUEST['page'] ) && 'pmpro-reports' === $_REQUEST['page'] ) {
			wp_register_script( 'pmpro_sws_reports', plugins_url( 'includes/js/pmpro-sws-reports.js', PMPROSWS_BASENAME ), array( 'jquery' ), '1.0.4' );
			wp_enqueue_script( 'pmpro_sws_reports' );
		}
	}

	/**
	 * Setup JS vars and enqueue our JS for tracking user behavior
	 */
	public static function enqueue_tracking_js() {
		global $pmpro_pages;

		$options = PMPro_SWS_Settings::get_options();
		$active_sitewide_sale = $options['active_sitewide_sale_id'];
		wp_register_script( 'pmpro_sws_tracking', plugins_url( 'includes/js/pmpro-sws-tracking.js', PMPROSWS_BASENAME ), array( 'jquery', 'utils' ) );

		$used_discount_code = 0;
		if ( is_page( $pmpro_pages['confirmation'] ) ) {
			$order = new \MemberOrder();
			$order->getLastMemberOrder();
			if ( isset( $order->id ) ) {
				$code = $order->getDiscountCode();
				if ( isset( $code->id ) && $code->id . '' === get_post_meta( $active_sitewide_sale, 'pmpro_sws_discount_code_id', true ) . '' ) {
					$used_discount_code = 1;
				}
			}
		}

		$pmpro_sws_data = array(
			'landing_page'      => is_page( get_post_meta( $active_sitewide_sale, 'pmpro_sws_landing_page_post_id', true ) ),
			'confirmation_page' => is_page( $pmpro_pages['confirmation'] ),
			'checkout_page'     => is_page( $pmpro_pages['checkout'] ),
			'used_sale_code'    => $used_discount_code,
			'sitewide_sale_id'  => $active_sitewide_sale,
			'ajax_url'          => admin_url( 'admin-ajax.php' ),
		);

		wp_localize_script( 'pmpro_sws_tracking', 'pmpro_sws', $pmpro_sws_data );

		wp_enqueue_script( 'pmpro_sws_tracking' );

	}

	/**
	 * Ajax call to update SWS statistics
	 */
	public static function ajax_tracking() {
		global $wpdb;
		$sitewide_sale_id = $_POST['sitewide_sale_id'];
		$element = $_POST['element'];
		$reports = get_option( 'pmpro_sws_' . $sitewide_sale_id . '_tracking' );
		if ( false === $reports ) {
			$reports = array(
				'banner_impressions'   => 0,
				'landing_page_visits'  => 0,
				'landing_page_after_banner'         => 0,
				'checkout_conversions_with_code'    => 0,
				'checkout_conversions_without_code' => 0,
			);
		}
		if ( array_key_exists( $element, $reports ) ) {
			$reports[ $element ] += 1;
			update_option( 'pmpro_sws_' . $sitewide_sale_id . '_tracking', $reports, 'no' );
		} else {
			return -1;
		}
	}
}

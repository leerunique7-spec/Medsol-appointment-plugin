<?php
/**
 * Mailer wrapper.
 *
 * @package Medsol_Appointments
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Medsol_Mailer' ) ) :

class Medsol_Mailer {

	/**
	 * Send an email (HTML by default). Plays nice with SMTP plugins via wp_mail().
	 *
	 * @param string|array $to          Recipient(s).
	 * @param string       $subject     Subject (plain text).
	 * @param string       $body        Body (HTML or plain text).
	 * @param array        $args        {
	 *     @type string       $from_email   Override From email (default: admin_email).
	 *     @type string       $from_name    Override From name  (default: blogname).
	 *     @type string|array $reply_to     Reply-To address(es).
	 *     @type string|array $cc           CC address(es).
	 *     @type string|array $bcc          BCC address(es).
	 *     @type string       $content_type Content type. Default 'text/html; charset=UTF-8'.
	 *     @type array        $headers      Extra headers (strings).
	 *     @type array        $attachments  File paths.
	 * }
	 * @return bool True on success, false on failure.
	 */
	public static function send( $to, $subject, $body, array $args = array() ) {
		$defaults = array(
			'from_email'   => get_option( 'admin_email' ),
			'from_name'    => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'reply_to'     => array(),
			'cc'           => array(),
			'bcc'          => array(),
			'content_type' => 'text/html; charset=UTF-8',
			'headers'      => array(),
			'attachments'  => array(),
		);
		$args = wp_parse_args( $args, $defaults );

		// Normalize recipients to arrays of sanitized emails.
		$norm_emails = function( $list ) {
			$list = is_array( $list ) ? $list : ( $list ? array( $list ) : array() );
			$out  = array();
			foreach ( $list as $addr ) {
				$addr = trim( (string) $addr );
				if ( is_email( $addr ) ) {
					$out[] = $addr;
				}
			}
			return $out;
		};

		$to   = $norm_emails( $to );
		$cc   = $norm_emails( $args['cc'] );
		$bcc  = $norm_emails( $args['bcc'] );
		$rt   = $norm_emails( $args['reply_to'] );

		if ( empty( $to ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Medsol_Mailer: No valid recipient(s) provided.' );
			}
			return false;
		}

		$headers = array();

		// From:
		$from_email = is_email( $args['from_email'] ) ? $args['from_email'] : get_option( 'admin_email' );
		$from_name  = $args['from_name'] ?: wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$headers[]  = 'From: ' . self::format_address( $from_name, $from_email );

		// Reply-To:
		foreach ( $rt as $addr ) {
			$headers[] = 'Reply-To: ' . $addr;
		}

		// CC:
		foreach ( $cc as $addr ) {
			$headers[] = 'Cc: ' . $addr;
		}

		// BCC:
		foreach ( $bcc as $addr ) {
			$headers[] = 'Bcc: ' . $addr;
		}

		// Content type:
		if ( $args['content_type'] ) {
			$headers[] = 'Content-Type: ' . $args['content_type'];
		}

		// Extra headers:
		foreach ( (array) $args['headers'] as $h ) {
			if ( is_string( $h ) && $h !== '' ) {
				$headers[] = $h;
			}
		}

		/**
		 * Filters before sending.
		 */
		$to      = apply_filters( 'medsol_mailer_to', $to, $subject, $body, $args );
		$subject = apply_filters( 'medsol_mailer_subject', $subject, $to, $body, $args );
		$body    = apply_filters( 'medsol_mailer_body', $body, $to, $subject, $args );
		$headers = apply_filters( 'medsol_mailer_headers', $headers, $to, $subject, $body, $args );

		$sent = wp_mail( $to, $subject, $body, $headers, $args['attachments'] );

		if ( ! $sent && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Medsol_Mailer: wp_mail() reported failure to ' . implode( ',', $to ) );
		}

		return $sent;
	}

	/**
	 * Format a "Name <email@host>" header value safely.
	 */
	private static function format_address( $name, $email ) {
		$name = trim( preg_replace( '/[\r\n]+/', ' ', (string) $name ) );
		return sprintf( '%s <%s>', $name, $email );
	}
}

endif;

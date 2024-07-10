<?php
/**
 * Plugin Name: WooCommerce Gateway Boleto Parcelado Offline 
 * Plugin URI:
 * Description: Gateway customizado para a Salli, que habilita o pagamento por boleto parcelado offline.
 * Author: Pixel Desenvolvimento
 * Author URI: https://pixeldesenvolvimento.com.br
 * Version: 1.1.0
 * Text Domain: wcpg-special
 * Domain Path: /i18n/languages/
 *
 * Copyright: (c) 2018
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package   wcpg-special
 * @author    Me
 * @category  Admin
 * @copyright Copyright (c)  2016-2018
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */
defined( 'ABSPATH' ) or exit;
// Make sure WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    return;
}
/**
 * Add the gateway to WC Available Gateways
 *
 * @since 1.0.0
 * @param array $gateways all available WC gateways
 * @return array $gateways all WC gateways + Custom Special gateway
 */
function wc_add_special_to_gateways( $gateways ) {
    $gateways[] = 'WC_Gateway_Special';
    return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'wc_add_special_to_gateways' );
/**
 * Adds plugin page links
 *
 * @since 1.0.0
 * @param array $links all plugin links
 * @return array $links all plugin links + our custom links (i.e., "Settings")
 */
function wc_special_gateway_plugin_links( $links ) {
    $plugin_links = array(
        '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=special_payment' ) . '">' . __( 'Configure', 'wcpg-special' ) . '</a>'
    );
    return array_merge( $plugin_links, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_special_gateway_plugin_links' );
/**
 * Custom Payment Gateway
 *
 * Provides an Custom Payment Gateway; mainly for testing purposes.
 * We load it later to ensure WC is loaded first since we're extending it.
 *
 * @class       WC_Gateway_Special
 * @extends     WC_Payment_Gateway
 * @version     1.0.0
 * @package     WooCommerce/Classes/Payment
 * @author      Me
 */
add_action( 'plugins_loaded', 'wc_special_gateway_init', 11 );
function wc_special_gateway_init() {
    class WC_Gateway_Special extends WC_Payment_Gateway {

        public $domain;
      	public $order_total;

        /**
         * Constructor for the gateway.
         */
        public function __construct() {
            $this->id                 = 'special_payment';
            $this->domain             = 'wcpg-special';
            $this->icon               = apply_filters('woocommerce_payment_gateway_icon', '');
            $this->has_fields         = false;
            $this->method_title       = __( 'Boleto Parcelado Offline', $this->domain );
          	$this->order_total = WC_Payment_Gateway::get_order_total();
          	

            // Define "payment type" radio buttons options field
            for($i=2;$i<=$this->get_option( 'installments' );$i++) {
              $parcela = $this->order_total/$i;
              $parcela = strip_tags(wc_price($parcela));
              $arr[$i] = $i."x de ".$parcela;
            }    
            $this->options = $arr;

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title        = $this->get_option( 'title' );
            $this->description  = $this->get_option( 'description' );
            $this->instructions = $this->get_option( 'instructions' );
            $this->order_status = $this->get_option( 'order_status' );
            $this->status_text  = $this->get_option( 'status_text' );
          	$this->installments  = $this->get_option( 'installments' );

            // Actions
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            add_action( 'woocommerce_checkout_create_order', array( $this, 'save_order_payment_type_meta_data' ), 10, 2 );
            add_filter( 'woocommerce_get_order_item_totals', array( $this, 'display_transaction_type_order_item_totals'), 10, 3 );
            add_action( 'woocommerce_admin_order_data_after_billing_address',  array( $this, 'display_payment_type_order_edit_pages'), 10, 1 );
            add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );

            // Customer Emails
            add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
        }
        /**
         * Initialize Gateway Settings Form Fields
         */
        public function init_form_fields() {
            $this->form_fields = apply_filters( 'wc_special_payment_form_fields', array(
                'enabled' => array(
                    'title'   => __( 'Habilitar', $this->domain ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Habilitar Boleto Parcelado Offline', $this->domain ),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title'       => __( 'Título', $this->domain ),
                    'type'        => 'text',
                    'description' => __( 'This controls the title for the payment method the customer sees during checkout.', $this->domain ),
                    'default'     => __( 'Special Payment', $this->domain ),
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => __( 'Descrição', $this->domain ),
                    'type'        => 'textarea',
                    'description' => __( 'Payment method description that the customer will see on your checkout.', $this->domain ),
                    'default'     => __( 'Please remit payment to Store Name upon pickup or delivery.', $this->domain ),
                    'desc_tip'    => true,
                ),
                'instructions' => array(
                    'title'       => __( 'Instruções', $this->domain ),
                    'type'        => 'textarea',
                    'description' => __( 'Instructions that will be added to the thank you page and emails.', $this->domain ),
                    'default'     => '', // Empty by default
                    'desc_tip'    => true,
                ),
                'order_status' => array(
                    'title'       => __( 'Status do Pedido', $this->domain ),
                    'type'        => 'select',
                    'description' => __( 'Choose whether order status you wish after checkout.', $this->domain ),
                    'default'     => 'wc-completed',
                    'desc_tip'    => true,
                    'class'       => 'wc-enhanced-select',
                    'options'     => wc_get_order_statuses()
                ),
                'status_text' => array(
                    'title'       => __( 'Descrição do Status do Pedido', $this->domain ),
                    'type'        => 'text',
                    'description' => __( 'Set the text for the selected order status.', $this->domain ),
                    'default'     => __( 'Order is completed', $this->domain ),
                    'desc_tip'    => true,
                ),
                'installments' => array(
					'title'       => __( 'Parcelas', $this->domain ),
					'type'        => 'select',
					'description' => __( 'Define o limite de parcelas permitido. Mínimo 2, máximo 14.', $this->domain ),
					'desc_tip'    => true,
                    'options'           => array(
                        '2' => __('2', $this->domain ),
                        '3' => __('3', $this->domain ),
                        '4' => __('4', $this->domain ),
                        '5' => __('5', $this->domain ),
                        '6' => __('6', $this->domain ),
                        '7' => __('7', $this->domain ),
                        '8' => __('8', $this->domain ),
                        '9' => __('9', $this->domain ),
                        '10' => __('10', $this->domain ),
                        '11' => __('11', $this->domain ),
                        '12' => __('12', $this->domain ),
                        '13' => __('13', $this->domain ),
                        '14' => __('14', $this->domain )
                    )
				),
            ) );
        }

        /**
         * Output the "payment type" radio buttons fields in checkout.
         */
        public function payment_fields(){
            if ( $description = $this->get_description() ) {
                echo wpautop( wptexturize( $description ) );
            }

            //echo '<style>#transaction_type_field label.radio { display:inline-block; margin:0 .8em 0 .4em}</style>';

            $option_keys = array_keys($this->options);

            woocommerce_form_field( 'custom-installments', array(
                'type'          => 'select',
                'class'         => array('transaction_type form-row-wide'),
                'label'         => __('Parcelas', $this->domain),
                'options'       => $this->options,
            ), reset( $option_keys ) );
          
          	woocommerce_form_field( 'custom-ticket-date', array(
                'type'          => 'select',
                'class'         => array('ticket-date form-row-wide'),
                'label'         => __('Data de vencimento', $this->domain),
                'options'       => array(
                					'5' => __('5', $this->domain ),
                					'10' => __('10', $this->domain ),
                                    '15' => __('15', $this->domain ),
                                    '20' => __('20', $this->domain ),
                                    '25' => __('25', $this->domain ),
                                    '30' => __('30', $this->domain ),
                ),
            ), reset( $option_keys ) );
        }

        /**
         * Save the chosen payment type as order meta data.
         *
         * @param object $order
         * @param array $data
         */
        public function save_order_payment_type_meta_data( $order, $data ) {
            if ( $data['payment_method'] === $this->id && isset($_POST['custom-installments']) && isset($_POST['custom-ticket-date']) ) {
                $order->update_meta_data('_custom-installments', esc_attr($_POST['custom-installments']) );
              	$order->update_meta_data('_custom-ticket-date', esc_attr($_POST['custom-ticket-date']) );
            }
        }

        /**
         * Output for the order received page.
         *
         * @param int $order_id
         */
        public function thankyou_page( $order_id ) {
            $order = wc_get_order( $order_id );

            if ( $this->instructions ) {
                echo wpautop( wptexturize( $this->instructions ) );
            }
        }

        /**
         * Display the chosen payment type on the order edit pages (backend)
         *
         * @param object $order
         */
        public function display_payment_type_order_edit_pages( $order ){
            if( $this->id === $order->get_payment_method() && $order->get_meta('_custom-installments') && $order->get_meta('_custom-ticket-date') ) {
              	
              	$installments = $order->get_meta('_custom-installments');
              	$order_total = $order->get_total();
              	$installment_total = $order_total/$installments;
                echo '<p><strong>'.__('Parcelas').':</strong> ' .$installments.'x de '. strip_tags(wc_price($installment_total)) . '</p>';
              
              	$ticket_date = $order->get_meta('_custom-ticket-date');
              	echo '<p><strong>'.__('Data de vencimento').':</strong> ' .$ticket_date. '</p>';
              
            }
        }

        /**
         * Display the chosen payment type on order totals table
         *
         * @param array    $total_rows
         * @param WC_Order $order
         * @param bool     $tax_display
         * @return array
         */
        public function display_transaction_type_order_item_totals( $total_rows, $order, $tax_display ){
            if( is_a( $order, 'WC_Order' ) && $order->get_meta('_custom-installments') && $order->get_meta('_custom-ticket-date') ) {
                $new_rows = []; // Initializing
                $options  = $this->options;

                // Loop through order total lines
                foreach( $total_rows as $total_key => $total_values ) {
                    $new_rows[$total_key] = $total_values;
                  
                  	$installments = $order->get_meta('_custom-installments');
                    $order_total = $order->get_total();
                    $installment_total = $order_total/$installments;
                  
                  	$ticket_date = $order->get_meta('_custom-ticket-date');
                  
                    if( $total_key === 'payment_method' ) {
                        $new_rows['payment_type'] = [
                            'label' => __("Parcelas", $this->domain) . ':',
                            'value' => $installments.'x de '. strip_tags(wc_price($installment_total)),
                        ];
                      	$new_rows['payment_type'] = [
                            'label' => __("Data de vencimento", $this->domain) . ':',
                            'value' => $ticket_date,
                        ];
                    }
                }

                $total_rows = $new_rows;
            }
            return $total_rows;
        }

        /**
         * Add content to the WC emails.
         *
         * @access public
         * @param WC_Order $order
         * @param bool $sent_to_admin
         * @param bool $plain_text
         */
        public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
            if ( $this->instructions && ! $sent_to_admin && $this->id === $order->get_payment_method()
            && $order->has_status( $this->order_status ) ) {
                echo wpautop( wptexturize( $this->instructions ) ) . PHP_EOL;
            }
        }

        /**
         * Process the payment and return the result
         *
         * @param int $order_id
         * @return array
         */
        public function process_payment( $order_id ) {
            $order = wc_get_order( $order_id );

            // Mark as on-hold (we're awaiting the payment)
            $order->update_status( $this->order_status, $this->status_text );

            // Reduce stock levels
            wc_reduce_stock_levels( $order->get_id() );

            // Remove cart
            WC()->cart->empty_cart();

            // Return thankyou redirect
            return array(
                'result'    => 'success',
                'redirect'  => $this->get_return_url( $order )
            );
        }
    }
}
<?php
/*
 * Plugin Name: Easy Digital Downloads Payment Gateway - CashBill
 * Plugin URL: http://cashbill.pl
 * Description: CashBill is easy to use electronic payment system. You can integrate our payment package with your website and offer customers secure payments.
 * Version: 1.0
 * Author: Łukasz Firek
 */
function edd_cashbill_register_gateway($gateways)
{
    global $edd_options;
    $gateways['cashbill'] = array(
        'admin_label' => 'CashBill',
        'checkout_label' => $edd_options['cashbill_displayed_name']
    );
    return $gateways;
}
add_filter( 'edd_payment_gateways','edd_cashbill_register_gateway' );

add_action( 'edd_cashbill_cc_form', '__return_false' );

function edd_cashbill_process_payment( $purchase_data ) {

    global $edd_options;

    $errors = edd_get_errors();
    if ( ! $errors ) {

        $purchase_summary = edd_get_purchase_summary( $purchase_data );

        $payment = array(
            'price'        => $purchase_data['price'],
            'date'         => $purchase_data['date'],
            'user_email'   => $purchase_data['user_email'],
            'purchase_key' => $purchase_data['purchase_key'],
            'currency'     => $edd_options['currency'],
            'downloads'    => $purchase_data['downloads'],
            'cart_details' => $purchase_data['cart_details'],
            'user_info'    => $purchase_data['user_info'],
            'status'       => 'pending'
        );

        $payment = edd_insert_payment( $payment );

        $fail = false;
    } else {
        $fail = true; 
    }

    if ( $fail == true ) {
        edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
    }else
    {
        if ($edd_options['cashbill_test']) {
            $restUrl = 'https://pay.cashbill.pl/testws/rest/';
        } else {
            $restUrl = 'https://pay.cashbill.pl/ws/rest/';
        }
       
       $QueryOrder = array(
           'title'=> 'Zamówienie Numer : '.$payment,
           'amount.value'=>$purchase_data['price'],
           'amount.currencyCode'=>edd_get_currency(),
           'returnUrl'=> add_query_arg('payment-confirmation', 'cashbill', get_permalink($edd_options['success_page'])),
           'description'=>'Zamówienie numer : '.$payment,
           'additionalData'=>$payment,
           'referer'=>'easydigitaldownloads',
           'personalData.email'=>$purchase_data['user_email'],
       );
        
       $sign = SHA1(implode("",$QueryOrder).$edd_options['cashbill_key']);
       $QueryOrder['sign'] = $sign;
       edd_empty_cart();

       
       $response = wp_remote_post( $restUrl.'payment/'.$edd_options['cashbill_id'], array(
           'method'    => 'POST',
           'timeout'   => 90,
           'body' => $QueryOrder,
           'sslverify' => false,
       ) );
       
       
       $response = json_decode($response['body']);
       
       
       header("location: {$response->redirectUrl}");
    }
    
}
add_action( 'edd_gateway_cashbill', 'edd_cashbill_process_payment' );

function cashbill_add_settings($settings) {

    $cashbill_settings = array(
        array(
            'id' => 'cashbill_settings',
            'name' => '<span class="field-section-title"><img src="'.plugins_url( 'img/cashbill_100x39.png', __FILE__ ).'" /></span>',
            'desc' => 'Konfiguracja',
            'type' => 'header'
        ),
        array(
            'id' => 'cashbill_displayed_name',
            'name' => 'Wyświetlana nazwa',
            'desc' => 'Nazwa wyświetlana podczas wyboru metody płatności',
            'type' => 'text',
            'size' => 'regular',
            'default' => 'Szybkie Płatności CashBill',
        ),
        array(
            'id' => 'cashbill_id',
            'name' => 'Indetyfikator Punktu Płatności',
            'desc' => 'Identyfikator nadany przy zakładaniu punktu płatności',
            'type' => 'text',
            'size' => 'regular'
        ),
        array(
            'id' => 'cashbill_key',
            'name' => 'Klucz Punktu Płatności',
            'desc' => 'Klucz nadawany przy zakładaniu punktu płatności',
            'type' => 'text',
            'size' => 'regular'
        ),
        array(
			'id' => 'cashbill_test',
			'name' => 'Tryb Pracy',
			'desc' => 'Wybierz tryb działania punktu płatności',
			'type' => 'select',
			'options' => array( '1' => 'Testowy',
								'0'=>'Produkcyjny', 
								),
			'size' => 'regular'
		),
        array(
            'id' => 'cashbill_pdf',
            'name' => '<a href="'.plugins_url( 'pdf/Instrukcja instalacji.pdf', __FILE__ ).'" target="_blank"><img src="'.plugins_url( 'img/pdf-icon.png', __FILE__ ).'" /> Instrukcja Instalacji</a>',
            'type' => 'hook',
        )
    );

    return array_merge($settings, $cashbill_settings);
}

add_filter('edd_settings_gateways', 'cashbill_add_settings');

function cashbill_callback() {
    global $edd_options;

if(isset($_GET['cmd']) && isset($_GET['args']) && isset($_GET['sign']))
{

    if(md5($_GET['cmd'].$_GET['args'].$edd_options['cashbill_key']) == $_GET['sign']) 
    {
        if ($edd_options['cashbill_test']) {
            $restUrl = 'https://pay.cashbill.pl/testws/rest/';
        } else {
            $restUrl = 'https://pay.cashbill.pl/ws/rest/';
        }
        
        $signature = SHA1($_GET['args'].$edd_options['cashbill_key']);
        $response = wp_remote_get( $restUrl.'payment/'.$edd_options['cashbill_id'].'/'.$_GET['args'].'?sign='.$signature);
        $response = json_decode($response['body']);
        
        if($response->status == 'PositiveFinish')
        {
            
            edd_insert_payment_note($response->additionalData, 'Płatność na kwotę ' . $response->amount->value . ' ' . $response->amount->currencyCode . ' została przyjęta przez CashBill.');
            edd_update_payment_status($response->additionalData, 'publish');

        }
        if($response->status == 'Abort' || $response->status == 'Fraud' || $response->status == 'NegativeFinish')
        {
            edd_insert_payment_note($response->additionalData, 'Płatność nie została przyjęta przez CashBill system zwrócił status ' . $response->status);
            edd_update_payment_status($response->additionalData, 'failed');
        }
        echo 'OK';
    }else
    {
        echo "BLAD SYGNATURY";
    }
  exit();  
}
     
}

add_action('init', 'cashbill_callback');

function add_admin_menu(){
    add_menu_page( 'Płatności CashBill', 'Płatności CashBill', 'manage_options','edit.php?post_type=download&page=edd-settings&tab=gateways#edd_settings[cashbill_displayed_name]', '', plugins_url( 'img/cashbill_50x50.png', __FILE__ ), 56 );
}

add_action( 'admin_menu', 'add_admin_menu' );
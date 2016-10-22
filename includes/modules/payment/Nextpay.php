<?php

class  osC_Payment_Nextpay extends osC_Payment {
    var $_title,
        $_code = 'Nextpay',
        $_status = false,
        $_sort_order,
        $_order_id;

    function osC_Payment_Nextpay() {
        global $order, $osC_Database, $osC_Language, $osC_ShoppingCart;

        $this->_title = $osC_Language->get('payment_Nextpay_title');
        $this->_method_title = $osC_Language->get('payment_Nextpay_method_title');
        $this->_status = (MODULE_PAYMENT_NEXTPAY_STATUS == '1') ? true : false;
        $this->_sort_order = MODULE_PAYMENT_NEXTPAY_SORT_ORDER;

        $this->form_action_url = 'https://www.pec24.com/pecpaymentgateway/default.aspx'; /* do not change */

        if ($this->_status === true) {
            if ((int)MODULE_PAYMENT_NEXTPAY_ORDER_STATUS_ID > 0) {
                $this->order_status = MODULE_PAYMENT_NEXTPAY_ORDER_STATUS_ID;
            }

            if ((int)MODULE_PAYMENT_NEXTPAY_ZONE > 0) {
                $check_flag = false;

                $Qcheck = $osC_Database->query('select zone_id from :table_zones_to_geo_zones where geo_zone_id = :geo_zone_id and zone_country_id = :zone_country_id order by zone_id');
                $Qcheck->bindTable(':table_zones_to_geo_zones', TABLE_ZONES_TO_GEO_ZONES);
                $Qcheck->bindInt(':geo_zone_id', MODULE_PAYMENT_NEXTPAY_ZONE);
                $Qcheck->bindInt(':zone_country_id', $osC_ShoppingCart->getBillingAddress('country_id'));
                $Qcheck->execute();

                while ($Qcheck->next()) {
                    if ($Qcheck->valueInt('zone_id') < 1) {
                        $check_flag = true;
                        break;
                    } elseif ($Qcheck->valueInt('zone_id') == $osC_ShoppingCart->getBillingAddress('zone_id')) {
                        $check_flag = true;
                        break;
                    }
                }

                if ($check_flag === false) {
                    $this->_status = false;
                }
            }
        }
    }

    function selection() {
        return array('id' => $this->_code,
            'module' => $this->_method_title);
    }

    function pre_confirmation_check() {
        return false;
    }

    function confirmation() {
        global $osC_Language, $osC_CreditCard;

        $this->_order_id = osC_Order :: insert(ORDERS_STATUS_PREPARING);

        $confirmation = array('title' => $this->_method_title,
            'fields' => array(array('title' => $osC_Language->get('payment_Nextpay_description'))));

        return $confirmation;
    }

    function process_button() {
        global $osC_Currencies, $osC_ShoppingCart, $osC_Language;
        include_once dirname(__FILE__).'/include/nextpay_payment.php';

        $amount = round($osC_Currencies->formatRaw($osC_ShoppingCart->getTotal(), 'IRR'), 2) / 10;
        $order_id = $this->_order_id;
        $api_key = MODULE_PAYMENT_NEXTPAY_API_KEY;
        $callback_uri = osc_href_link(FILENAME_CHECKOUT, 'process', 'SSL', null, null, true);


        $parameters = array
        (
            "api_key"=>$api_key,
            "order_id"=> $order_id,
            "amount"=>$amount,
            "callback_uri"=>$callback_uri
        );


        $nextpay = new Nextpay_Payment($parameters);
        $result = $nextpay->token();

        if(intval($result->code) == -1)
        {
            echo '<div style="text-align:left;">' . osc_link_object(osc_href_link('http://api.nextpay.org/gateway/payment/' . $result->trans_id, '', '', '', false), osc_draw_image_button('button_confirm_order.gif', $osC_Language->get('button_confirm_order'), 'id="btnConfirmOrder"')) . '</div>';
        }
        else
        {
            osC_Order::remove($this->_order_id);

            $message = ' شماره خطا: '.$result->code.'<br />';
            $message .= '</br>' . $nextpay->code_error(intval($result->code));

            echo '<div style="font-size:11px; color:#cc0000; width:500; border:1px solid #cc0000; padding:5px; background:#ffffcc;">'  . $message . '</div>';

        }

    }

    function get_error() {
        return false;
    }

    function process() {
        global $osC_Database, $osC_Customer, $osC_Currencies, $osC_ShoppingCart, $_POST, $_GET, $osC_Language, $messageStack;
        include_once dirname(__FILE__).'/include/nextpay_payment.php';

        $trans_id = $_POST['trans_id'];
        $order_id = $_POST['order_id'];
        $api_key = MODULE_PAYMENT_NEXTPAY_API_KEY;
        $amount = round($osC_Currencies->formatRaw($osC_ShoppingCart->getTotal(), 'IRR'), 2) / 10;
        $this->_order_id = osC_Order::insert(ORDERS_STATUS_PREPARING);

        $parameters = array
        (
            'api_key'	=> $api_key,
            'order_id'	=> $order_id,
            'trans_id' 	=> $trans_id,
            'amount'	=> $amount,
        );

        $nextpay = new Nextpay_Payment();
        $result = $nextpay->verify_request($parameters);

        if( $result != 0 )
        {

            osC_Order :: remove($this->_order_id);
            $messageStack->add_session('checkout', callback_state_error_Nextpay($result), 'error');
            osc_redirect(osc_href_link(FILENAME_CHECKOUT, 'checkout&view=paymentInformationForm', 'SSL', null, null, true));

        }
        else
        {
            if($order_id == $this->_order_id)
            {

                // insert ref id in database
                $osC_Database->simpleQuery("insert into `" . DB_TABLE_PREFIX . "online_transactions`
					  		(orders_id,receipt_id,transaction_method,transaction_date,transaction_amount,transaction_id) values
		                    ('$order_id','$trans_id','Nextpay','" . date("YmdHis") . "','$amount','$trans_id')
					  ");
                //
                $Qtransaction = $osC_Database->query('insert into :table_orders_transactions_history (orders_id, transaction_code, transaction_return_value, transaction_return_status, date_added) values (:orders_id, :transaction_code, :transaction_return_value, :transaction_return_status, now())');
                $Qtransaction->bindTable(':table_orders_transactions_history', TABLE_ORDERS_TRANSACTIONS_HISTORY);
                $Qtransaction->bindInt(':orders_id', $order_id);
                $Qtransaction->bindInt(':transaction_code', 1);
                $Qtransaction->bindValue(':transaction_return_value', $trans_id);
                $Qtransaction->bindInt(':transaction_return_status', 1);
                $Qtransaction->execute();
                //
                $this->_order_id = osC_Order :: insert();
                $comments = $osC_Language->get('payment_Nextpay_method_trans_id') . '[' . $trans_id . ']';
                osC_Order :: process($this->_order_id, $this->order_status, $comments);
            }
            else
            {
                osC_Order :: remove($this->_order_id);
                $messageStack->add_session('checkout', 'این رسید پرداخت مربوط به این شماره سفارش نمی باشد.', 'error');
                osc_redirect(osc_href_link(FILENAME_CHECKOUT, 'checkout&view=paymentInformationForm', 'SSL', null, null, true));

            }
        }

    }
}

function callback_state_error_Nextpay($State){

    return  'خطا کد : ' . $State ;
}
?>
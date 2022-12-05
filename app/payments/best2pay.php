<?php

if(!defined('BOOTSTRAP')){
    die('Access denied');
}

if(defined('PAYMENT_NOTIFICATION')){
    if(($mode == 'success') || ($mode == 'fail')){
        $operation_id = !empty($_REQUEST['operation']) ? (int)$_REQUEST['operation'] : 0;
        $order_id = !empty($_REQUEST['order_id']) ? (int)$_REQUEST['order_id'] : 0;
        $id = !empty($_REQUEST['id']) ? (int)$_REQUEST['id'] : 0;
        $order_info = fn_get_order_info($order_id);
        $sector_id = isset($order_info['payment_method']['processor_params']['project_id']) ?
            $order_info['payment_method']['processor_params']['project_id'] : null;
        $password = isset($order_info['payment_method']['processor_params']['sign']) ?
            $order_info['payment_method']['processor_params']['sign'] : null;
        $test = isset($order_info['payment_method']['processor_params']['test']) ?
            $order_info['payment_method']['processor_params']['test'] : null;
        $TAX = (
            isset($order_info['payment_method']['processor_params']['tax'])
            && $order_info['payment_method']['processor_params']['tax'] > 0
            && $order_info['payment_method']['processor_params']['tax'] <= 6
        ) ? $order_info['payment_method']['processor_params']['tax'] : 6;
        if(!$test){
            $best2pay_url = 'https://pay.best2pay.net';
        }
        else {
            $best2pay_url = 'https://test.best2pay.net';
        }
        $signature = base64_encode(md5($sector_id . $id . $operation_id . $password));
        $url = $best2pay_url . '/webapi/Operation';
        $context = stream_context_create(array(
            'http' => array(
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query(array(
                    'sector' => $sector_id,
                    'id' => $id,
                    'operation' => $operation_id,
                    'signature' => $signature
                )),
            )
        ));
        $xml = file_get_contents($url, false, $context);

        if(!$xml){
            throw new Exception("Empty data");
        }
        $xml = simplexml_load_string($xml);
        if(!$xml){
            throw new Exception("Non valid XML was received");
        }
        $response = json_decode(json_encode($xml), true);
        if(!$response){
            throw new Exception("Non valid XML was received");
        }

        $tmp_response = (array)$response;
        unset($tmp_response['signature'], $tmp_response['ofd_state']);
        $signature = base64_encode(md5(implode('', $tmp_response) . $password));
        if($signature !== $response['signature']){
            throw new Exception("Invalid signature");
        }
        $pp_response['transaction_id'] = $id;
        if(($response['type'] == 'PURCHASE_BY_QR' || $response['type'] == 'PURCHASE' || $response['type'] == 'AUTHORIZE') && $response['state'] == 'APPROVED'){
            $pp_response['order_status'] = 'P';
            $pp_response['reason_text'] = 'Заказ успешно оплачен';
        }
        else {
            $pp_response['order_status'] = 'F';
            $pp_response['reason_text'] = 'Ошибка оплаты';
        }
        fn_finish_payment($order_id, $pp_response);
        fn_order_placement_routines('route', $order_id);
    }
    else {
        if($mode == 'notify'){

            $xml = file_get_contents("php://input");
            if(!$xml){
                throw new Exception("Empty data");
            }
            $xml = simplexml_load_string($xml);
            if(!$xml){
                throw new Exception("Non valid XML was received");
            }
            $response = json_decode(json_encode($xml));
            if(!$response){
                throw new Exception("Non valid XML was received");
            }

            if($response->reason_code){

                $order_id = $response->reference;
                $order_info = fn_get_order_info($order_id);
                $pp_response['transaction_id'] = $id;

                if($response->reason_code == 1){
                    $pp_response['order_status'] = 'P';
                    $pp_response['reason_text'] = 'Заказ успешно оплачен';
                }
                else {
                    $pp_response['order_status'] = 'F';
                    $pp_response['reason_text'] = 'Ошибка оплаты';
                }
                fn_finish_payment($order_id, $pp_response);
                echo "ok";
            }
        }
    }

}
else {
    $sector_id = isset($order_info['payment_method']['processor_params']['project_id']) ?
        $order_info['payment_method']['processor_params']['project_id'] : null;
    $password = isset($order_info['payment_method']['processor_params']['sign']) ?
        $order_info['payment_method']['processor_params']['sign'] : null;
    $test = isset($order_info['payment_method']['processor_params']['test']) ?
        $order_info['payment_method']['processor_params']['test'] : null;
    $halva = isset($order_info['payment_method']['processor_params']['halva']) ?
        (bool) $order_info['payment_method']['processor_params']['halva'] : null;
    $TAX = (
        isset($order_info['payment_method']['processor_params']['tax'])
        && $order_info['payment_method']['processor_params']['tax'] > 0
        && $order_info['payment_method']['processor_params']['tax'] <= 6
    ) ? $order_info['payment_method']['processor_params']['tax'] : 6;
    if(!$test){
        $best2pay_url = 'https://pay.best2pay.net';
    }
    else {
        $best2pay_url = 'https://test.best2pay.net';
    }
    $register_url = $best2pay_url . '/webapi/Register';
    $currency = '643';
    $amount = intval($order_info["total"] * 100);
    $signature = base64_encode(md5($sector_id . $amount . $currency . $password));
    $confirm_url = fn_url("payment_notification.success?payment=best2pay&order_id=$order_id", AREA, 'current');
    $cancel_url = fn_url("payment_notification.fail?payment=best2pay&order_id=$order_id", AREA, 'current');

    $fiscalPositions = '';
    $fiscalAmount = 0;
    $shop_cart = [];
    $sc_key = 0;
    foreach($order_info['products'] as $product) {
        $shop_cart[$sc_key]['name'] = $product['product'];
        $fiscalPositions .= $product['amount'] . ';';
        $shop_cart[$sc_key]['quantityGoods'] = (int) $product['amount'];
        $elementPrice = intval(round($product['price'] * 100));
        $fiscalPositions .= $elementPrice . ';';
        $shop_cart[$sc_key]['goodCost'] = round($product['price'] * $shop_cart[$sc_key]['quantityGoods'], 2);
        $fiscalPositions .= $TAX . ';';
        $fiscalPositions .= $product['product'] . '|';
        $fiscalAmount += $product['amount'] * $elementPrice;
        $sc_key++;
    }
    if($order_info['shipping_cost'] > 0){
        $fiscalPositions .= '1;';
        $shop_cart[$sc_key]['quantityGoods'] = 1;
        $elementPrice = intval(round($order_info['shipping_cost'] * 100));
        $fiscalPositions .= $elementPrice . ';';
        $shop_cart[$sc_key]['goodCost'] = round($order_info['shipping_cost'], 2);
        $fiscalPositions .= $TAX . ';';
        $fiscalPositions .= 'Доставка' . '|';
        $shop_cart[$sc_key]['name'] = 'Доставка';
        $fiscalAmount += $elementPrice;
    }
    $fiscalDiff = abs($fiscalAmount - $amount);
    if ($fiscalDiff) {
        $fiscalPositions .= '1;' . $fiscalDiff . ';6;Скидка;14|';
        $shop_cart_encoded = '';
    } else {
        $shop_cart_encoded = base64_encode(json_encode($shop_cart, JSON_UNESCAPED_UNICODE));
    }

    $fiscalPositions = substr($fiscalPositions, 0, -1);

    $data = http_build_query(array(
        'sector' => $sector_id,
        'reference' => $order_id,
        'fiscal_positions' => $fiscalPositions,
        'amount' => $amount,
        'description' => 'Оплата заказа ' . $order_id,
        'email' => $order_info['email'],
        'phone' => $order_info['phone'],
        'currency' => $currency,
        'mode' => 1,
        'url' => $confirm_url,
        'failurl' => $cancel_url,
        'signature' => $signature
    ));

    $context = stream_context_create(array(
        'http' => array(
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n"
                . "Content-Length: " . strlen($data) . "\r\n",
            'method' => 'POST',
            'content' => $data
        )
    ));
    $b2p_order_id = file_get_contents($register_url, false, $context);
    if(intval($b2p_order_id) == 0){
        throw new Exception('error register order');
    }

    if ($halva) {
        $payment_path = '/webapi/custom/svkb/PurchaseWithInstallment';
        $post_data = [
            'sector' => $sector_id,
            'id' => $b2p_order_id,
            'shop_cart' => $shop_cart_encoded,
            'signature' => base64_encode(md5($sector_id . $b2p_order_id . $shop_cart_encoded . $password))
        ];
    }
    else {
        $payment_path = '/webapi/Purchase';
        $post_data = [
            'sector' => $sector_id,
            'id' => $b2p_order_id,
            'signature' => base64_encode(md5($sector_id . $b2p_order_id . $password))
        ];
    }

    $form_url = $best2pay_url . $payment_path;

    fn_create_payment_form($form_url, $post_data, 'Best2Pay', false);
}
exit;

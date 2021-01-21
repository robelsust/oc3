<?php
function getAllCitiesSaudi($api_key)
{
  $authorization = "Authorization: Bearer ".$api_key;
  $url = 'https://c.quick.sa.com/API/V3/GetConsistentData';
  
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json' , $authorization ));
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

  if (ini_get('open_basedir') == '' && strtolower(ini_get('safe_mode')) == 'off'){
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	}

  $result = curl_exec($ch);
  curl_close($ch);
  $cityData = json_decode($result);
 
  if(is_object($cityData)){
    if($cityData->httpStatusCode == 200 && $cityData->isSuccess== 1){
       $cities =  @$cityData->resultData->countryCityList[0]->cities;
       return $cities;
    }
  }else{
    return null;
  }

}

function getApiKey($username,$pass){

    $url = 'https://c.quick.sa.com/API/Login/GetAccessToken';

    $orderData = array();
    $orderData['username'] = $username;
    $orderData['password'] = $pass;
    $orderData['grant_type'] = 'password';

    $orderPostData = '';
    foreach($orderData as $k=>$v){
      $orderPostData.=$k.'='.$v.'&';
    }       

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POSTFIELDS,$orderPostData);

    if (ini_get('open_basedir') == '' && strtolower(ini_get('safe_mode')) == 'off'){
      curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    }

    $result = curl_exec($ch);
    curl_close($ch);
    return json_decode($result);
      
}



function callApi($order,$api_key, $orderExistinLogTable=0,$this_db,$this_config)
  {
    $orderId = $order['order_id'];
    $dateAdded = time();
  
    $query = $this_db->query("SELECT * FROM " . DB_PREFIX . "order_total WHERE order_id = '" . (int)$order['order_id'] . "' ORDER BY sort_order");
    $order_totals = $query->rows;
    $subtotal = 0;
        foreach ($order_totals as $order_total) {
  
          if ($order_total['code'] == 'sub_total') {
            $subtotal = $order_total['value'];
            break;
          }
        }
  
    $orderData = array();
    $orderData['SandboxMode'] = false;
    $orderData['CustomerName'] = trim($order['firstname'].' '.$order['lastname']);
    $orderData['CustomerPhoneNumber'] = $order['telephone'];
    $orderData['ExternalStoreShipmentIdRef'] = $order['order_id'];
    $orderData['NotesFromStore'] = htmlentities(strip_tags($order['comment']));
    if($order['payment_code']=='cod'){
      $orderData['PaymentMethodId'] = 4;
    }else{
      $orderData['PaymentMethodId'] = 1;
    }
    $orderData['ShipmentContentValueSAR'] = $subtotal;
    $orderData['ShipmentContentTypeId'] = 5;
    $orderData['AddedServicesIds'] = [];
  
    //New code copied from Client 
    //Target order Id
    $orderId = $order['order_id'];
    //Get order data
    $orderInDb = null;    
    $query = "SELECT * FROM " . DB_PREFIX . "order WHERE " . DB_PREFIX . "order.order_id = {$orderId}";
    $query_result = $this_db->query($query);
    $query_result_array = $query_result->rows;
    $orderInDb =  $query_result_array[0];
    
    //Get order products
    $OrderProducts = array();   
    $query = "SELECT *, oc_order_product.quantity AS ProductOrderQuantity, oc_order_product.price AS ProductPriceWithItsServices, oc_order_product.total AS ProductTotalPrice, oc_product.price AS ProductPrice FROM oc_order_product LEFT JOIN oc_product ON oc_order_product.product_id = oc_product.product_id WHERE oc_order_product.order_id = {$orderId}";
    $query_result = $this_db->query($query);
    $query_result_array = $query_result->rows;
  
    //Loop through products and add them to the array
    foreach($query_result_array as $ProductInDb){
      $product = array();
  
      $product["Id"] = $ProductInDb["product_id"];
      $product["SKU"] = $ProductInDb["sku"];
      $product["Name"] = $ProductInDb["model"] .' - '. $ProductInDb["name"];
      $product["Quantity"] = $ProductInDb["ProductOrderQuantity"];
      $product["UnitPrice"] = $ProductInDb["ProductPrice"];
      $product["TotalPrice"] = $ProductInDb["ProductTotalPrice"];
      array_push($OrderProducts, $product);
  
    }
  
  
    $SkipProduct = true;

  
    //GET Storage Settings  
    $quick_inventory = $this_config->get('quick_inventory');
    if( $quick_inventory ){
      $SkipProduct = false;
    }
  
    //Attach products object to Request body
    if(!$SkipProduct)
    {
      $itemsObject = array();
      $itemsObject["Items"] = $OrderProducts;
  
      $orderData["UseQuickInventory"] = $itemsObject;
    }      
  
    $log = new Log('quick.log');

    //$CustomerLocationDescription = trim($order['shipping_city']).' - '.trim($order['shipping_zone']).' - '.trim($order['shipping_address_1'].' '.$order['shipping_address_2']);
  
    // #Update Oct,2 2020
    $CustomerLocationDescription = $order['shipping_address_1'].' '.$order['shipping_address_2'];
    //Non word chars removed -*
    $CustomerLocationDescription = preg_replace("/[^\p{L}\p{N}_]+/u", " ", $CustomerLocationDescription);
    //Duplicates removed
    $CustomerLocationDescription = implode(' ',array_unique(explode(' ', $CustomerLocationDescription)));
    //
    $CustomerLocationDescription = str_replace(" - ", "", $CustomerLocationDescription);
    //Trimed
    $CustomerLocationDescription = trim($CustomerLocationDescription);
  
    // 
    $orderData['CustomerLocation'] = array(
      'Desciption'  =>  $CustomerLocationDescription,
      'CountryId'   =>  1,
      'CityAsString'      =>  trim($order['shipping_zone'])
    );	
  
    $orderDataPost = json_encode($orderData);
    $log->write('orderDataPost: ' . $orderDataPost);    

    $url = 'https://c.quick.sa.com/API/V3/Store/Shipment';
    $authorization = "Authorization: Bearer ".$api_key;
  
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json' , $authorization ));
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
      curl_setopt($ch, CURLOPT_POSTFIELDS,$orderDataPost);
      
        if (ini_get('open_basedir') == '' && strtolower(ini_get('safe_mode')) == 'off'){
          curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        }
  
      $result = curl_exec($ch);
      curl_close($ch);
      $log->write('Response API: ' . $result); 
      $response =  json_decode($result);
	  
      if($response->httpStatusCode==200 || $response->httpStatusCode==201){
        $status = 1;
        //No Error
      }else{
        //Error Occured
        $status = 0;
      }
  	  $comment = $response->messageAr.'<br>'.$response->messageEn;
  
      if($orderExistinLogTable){
        $this_db->query("UPDATE " . DB_PREFIX . "quick_api SET  api_response = '" . $this_db->escape($result) . "', date_added = '". $this_db->escape($dateAdded) . "', status = $status WHERE order_id=".$orderId);
      }else{
        $this_db->query("INSERT INTO " . DB_PREFIX . "quick_api SET order_id = $orderId, api_response = '" . $this_db->escape($result) . "', date_added = '". $this_db->escape($dateAdded) . "', status = $status");
      }  
	  
      $status_id = $this_db->query("SELECT * from ". DB_PREFIX . "order_history  WHERE order_id=".$orderId." ORDER BY order_history_id DESC limit 1");
      $order_status_id = $status_id->row['order_status_id'];	  
	  
	  //Now update order history
		$this_db->query("INSERT INTO " . DB_PREFIX . "order_history SET order_id = '" . (int)$orderId . "', order_status_id = '" . (int)$order_status_id . "', notify = '0', comment = '" . $this_db->escape($comment) . "', date_added = NOW()");		  

  }



?>
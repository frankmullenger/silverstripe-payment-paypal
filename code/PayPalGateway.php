<?php

class PayPalGateway extends PaymentGateway_GatewayHosted {
	
	protected $supportedCurrencies = array(
    		'NZD' => 'New Zealand Dollar',
    		'USD' => 'United States Dollar',
    		'GBP' => 'Great British Pound',
    		'AUD' => 'Australian Dollar',
    		'CAD' => 'Canadian Dollar',
    		'CZK' => 'Czech Koruna',
    		'DKK' => 'Danish Krone',
    		'EUR' => 'Euro',
    		'HKD' => 'Hong Kong Dollar',
    		'HUF' => 'Hungarian Forint',
    		'JPY' => 'Japanese Yen',
    		'NOK' => 'Norwegian Krone',
    		'PLN' => 'Polish Zloty',
    		'SGD' => 'Singapore Dollar',
    		'SEK' => 'Swedish Krona',
    		'CHF' => 'Swiss Franc',
  	);
	
}

class PayPalGateway_Express extends PayPalGateway {
	
	public $tokenID;

	private function callAPI($data) {
		
		$config = $this->getConfig();
    		$authentication = $config['authentication'];
    		$endpoint = $config['endpoint'];

		$auth = array(
			'USER' => $authentication['username'],			
			'PWD'=> $authentication['password'],
			'SIGNATURE' => $authentication['signature'],
		);
		$data = array_merge($auth, $data);
		

		$conn = new RestfulService($endpoint, 0); //REST connection that will expire immediately
		$conn->httpHeader('Accept: application/xml');
		$conn->httpHeader('Content-Type: application/x-www-form-urlencoded');
		
		$response = $conn->request('', 'POST', http_build_query($data));
		return $response;
	}
	
	private function formatResponse($nvpstr){
		$intial = 0;
	 	$nvpArray = array();

		while(strlen($nvpstr)){
			//postion of Key
			$keypos= strpos($nvpstr,'=');
			
			//position of value
			$valuepos = strpos($nvpstr,'&') ? strpos($nvpstr,'&'): strlen($nvpstr);

			//getting the Key and Value values and storing in a Associative Array
			$keyval=substr($nvpstr,$intial,$keypos);
			$valval=substr($nvpstr,$keypos+1,$valuepos-$keypos-1);
			
			//decoding the respose
			$nvpArray[urldecode($keyval)] =urldecode( $valval);
			$nvpstr=substr($nvpstr,$valuepos+1,strlen($nvpstr));
	  	}
	  
		return $nvpArray;
	}
	
	/**
	 * Authorise the payment by processing SetExpressCheckout and retrieving the token to be saved on the payment
	 * https://www.x.com/developers/paypal/documentation-tools/api/setexpresscheckout-api-operation-nvp
	 * 
	 */ 
	public function authorise($data) {

		$payload = array(
			
			//Required 
			'VERSION' => '94.0',
			'METHOD' => 'SetExpressCheckout',
			'PAYMENTREQUEST_0_AMT' => $data['Amount'],
			'PAYMENTREQUEST_0_CURRENCYCODE' => $data['Currency'], 
			'RETURNURL' => $this->returnURL,
			'CANCELURL' => $this->cancelURL,
			
			//Required for digital goods
			'PAYMENTREQUEST_0_ITEMAMT' => $data['Amount'],
			'REQCONFIRMSHIPPING' => 0, //require that paypal account address be confirmed
			'NOSHIPPING' => 1, //show shipping fields, or not 0 = show shipping, 1 = don't show shipping, 2 = use account address, if none passed
			'PAYMENTREQUEST_0_PAYMENTACTION' => 'Sale',

			//Optional
			'LANDINGPAGE' => 'Billing', //can be 'Billing' or 'Login'
			'SOLUTIONTYPE' => 'Sole', //require paypal account, or not. Can be or 'Mark' (required) or 'Sole' (not required)
		);

		$response = $this->callAPI($payload);
		$body = $this->formatResponse($response->getBody());

		if(!isset($body['ACK']) || !(strtoupper($body['ACK']) == "SUCCESS" || strtoupper($body['ACK']) == "SUCCESSWITHWARNING")){
			return new PaymentGateway_Failure($response, 'You are attempting to make a payment without the necessary credentials set');
		}
		else {
			$this->tokenID = $body['TOKEN'];
			return new PaymentGateway_Success($response, 'You are attempting to make a payment without the necessary credentials set');
		}
	}
	
	public function process($data) {

		$config = $this->getConfig();
    		$url = $config['url'];

		$paymentURL = $url . $this->tokenID . '&useraction=commit'; //useraction=commit ensures the payment is confirmed on PayPal not on a merchant confirm page

		if (!$paymentURL) {
			return new PaymentGateway_Failure(null, 'URL could not be generated from token.');
		}
		Controller::curr()->redirect($paymentURL);
  }	
  
  /**
   * Confirm the payment by processing DoExpressCheckoutPayment
   * https://www.x.com/developers/paypal/documentation-tools/api/doexpresscheckoutpayment-api-operation-nvp
   */ 
  public function confirm($data) {
  	
  	$payload = array(
  		'VERSION' => '94.0',
  		'METHOD' => 'DoExpressCheckoutPayment',
  		'PAYERID' => $data['PayerID'],
			'TOKEN' => $data['Token'],
			'PAYMENTREQUEST_0_AMT' => $data['Amount'],
			'PAYMENTREQUEST_0_CURRENCYCODE' => $data['Currency'],
			'PAYMENTREQUEST_0_PAYMENTACTION' => 'Sale'
  	);

  	$response = $this->callAPI($payload);
		$body = $this->formatResponse($response->getBody());

		if(!isset($body['ACK']) || !(strtoupper($body['ACK']) == 'SUCCESS' || strtoupper($body['ACK']) == 'SUCCESSWITHWARNING')){
			$result = new PaymentGateway_Failure($response, 'You are attempting to make a payment without the necessary credentials set');
		}
		else {
			
			switch(strtoupper($body['PAYMENTINFO_0_PAYMENTSTATUS'])){
				case 'PROCESSED':
				case 'COMPLETED':
					$result = new PaymentGateway_Success(
						$response, 
						_t('PayPalPayment.SUCCESS', 'The payment has been completed, and the funds have been successfully transferred')
					);
					break;
				case 'EXPIRED':
					$result = new PaymentGateway_Failure(
						$response, 
						_t('PayPalPayment.AUTHORISATION', 'The authorization period for this payment has been reached')
					);
					break;	
				case 'DENIED':
					$result = new PaymentGateway_Failure(
						$response, 
						_t('PayPalPayment.DENIED', 'Payment was denied')
					);
					break;	
				case 'REVERSED':
					$result = new PaymentGateway_Failure(
						$response, 
						_t('PayPalPayment.REVERSED', 'Payment was reversed')
					);
					break;	
				case 'VOIDED':
					$result = new PaymentGateway_Failure(
						$response, 
						_t('PayPalPayment.VOIDED', 'An authorization for this transaction has been voided')
					);
					break;	
				case 'FAILED':
					$this->Status = 'Failure';
					$result = new PaymentGateway_Failure(
						$response, 
						_t('PayPalPayment.FAILED', 'Payment failed')
					);
					break;
				case 'IN-PROGRESS':
				case 'PENDING':
					$result = new PaymentGateway_Incomplete(
						$response, 
						_t('PayPalPayment.PENDING', 'The payment is pending because ' . $res['PAYMENTINFO_0_PENDINGREASON'])
					);
					break;
				case 'REFUNDED':
				case 'CANCEL-REVERSAL': // A reversal has been canceled; for example, when you win a dispute and the funds for the reversal have been returned to you.
				case 'PARTIALLY-REFUNDED':
					$result = new PaymentGateway_Success(
						$response, 
						_t('PayPalPayment.SUCCESS', 'The payment has been completed, and the funds have been successfully transferred')
					);
					break;	
					
				default:
					$result = new PaymentGateway_Incomplete(
						$response, 
						_t('PayPalPayment.DEFAULT', 'The payment is pending.')
					);
					break;
			}	
			
		}
		
		return $result;
  }	
}

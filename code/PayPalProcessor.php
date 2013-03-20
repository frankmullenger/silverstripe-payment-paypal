<?php

class PayPalProcessor_Express extends PaymentProcessor {

	public function capture($data) {

		parent::capture($data);
		
		// Set the return link
		$returnURL = Director::absoluteURL(Controller::join_links(
			$this->link(),
			'complete',
			$this->methodName,
			$this->payment->ID
		));
		$this->gateway->setReturnURL($returnURL);
		
		
		// Set the cancel link
		$AccountPage = AccountPage::get()->First();
		$cancelURL = Director::absoluteURL($AccountPage->Link());
		$this->gateway->setCancelURL($cancelURL);
		
		// Authorise the payment and get token 
		$result = $this->gateway->authorise($this->paymentData);
    
    		if ($result && !$result->isSuccess()) {
			$this->payment->updateStatus($result);
			$this->doRedirect();
			return;
		}

		// Save the token for good measure
    		$this->payment->Token = $this->gateway->tokenID;
    		$this->payment->write();

		// Process payment
		$result = $this->gateway->process($this->paymentData);

		// Processing may not get to here if all goes smoothly, customer will be at the 3rd party gateway
		if ($result && !$result->isSuccess()) {
			$this->payment->updateStatus($result);
			$this->doRedirect();
			return;
		}
	}

	public function complete($request) {

		// Reconstruct the payment object
		$this->payment = Payment::get()->byID($request->param('OtherID'));
		
		// Save the payer ID for good measure
    		$this->payment->PayerID = $request->getVar('PayerID');
    		$this->payment->write();

		// Reconstruct the gateway object
		$methodName = $request->param('ID');
		$this->gateway = PaymentFactory::get_gateway($methodName);

		// Confirm the payment
		$data = array(
			'PayerID' => $request->getVar('PayerID'),
			'Token' => $request->getVar('token'),
			'Amount' => $this->payment->Amount->Amount,
			'Currency' => $this->payment->Amount->Currency
		);

		$result = $this->gateway->confirm($data);
		$this->payment->updateStatus($result);

		// Do redirection
		$this->doRedirect();
		return;
	}

}

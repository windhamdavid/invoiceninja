<?php

namespace App\Ninja\PaymentDrivers;

use App\Models\AccountGateway;
use App\Models\Invitation;
use URL;
use Session;
use Request;
use Omnipay;
use Exception;
use CreditCard;
use DateTime;
use App\Models\AccountGatewayToken;
use App\Models\Account;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Country;

/**
 * Class BasePaymentDriver
 */
class BasePaymentDriver
{
    public $invitation;

    /**
     * @var AccountGateway
     */
    public $accountGateway;

    protected $gatewayType;
    protected $gateway;
    protected $customer;
    protected $sourceId;

    /**
     * @var array
     */
    protected $input;

    protected $customerResponse;
    protected $tokenResponse;
    protected $purchaseResponse;

    /**
     * @var string
     */
    protected $sourceReferenceParam = 'token';

    /**
     * @var string
     */
    protected $customerReferenceParam;

    /**
     * @var string
     */
    protected $transactionReferenceParam;

    /**
     * BasePaymentDriver constructor.
     *
     * @param AccountGateway $accountGateway
     * @param Invitation $invitation
     * @param bool $gatewayType
     */
    public function __construct(
        AccountGateway $accountGateway = null,
        Invitation $invitation = null,
        $gatewayType = false
    )
    {
        $this->accountGateway = $accountGateway;
        $this->invitation = $invitation;
        $this->gatewayType = $gatewayType ?: $this->gatewayTypes()[0];
    }

    public function isGateway($gatewayId)
    {
        return $this->accountGateway->gateway_id == $gatewayId;
    }

    // optionally pass a paymentMethod to determine the type from the token
    protected function isGatewayType($gatewayType, $paymentMethod = false)
    {
        if ($paymentMethod) {
            return $paymentMethod->gatewayType() == $gatewayType;
        } else {
            return $this->gatewayType === $gatewayType;
        }
    }

    /**
     * @return array
     */
    public function gatewayTypes()
    {
        return [
            GATEWAY_TYPE_CREDIT_CARD
        ];
    }

    public function handles($type)
    {
        return in_array($type, $this->gatewayTypes());
    }

    /**
     * When set to true we won't pass the card details with the form
     *
     * @return bool
     */
    public function tokenize()
    {
        return false;
    }

    /**
     * Set payment method as pending until confirmed
     *
     * @return bool
     */
    public function isTwoStep()
    {
        return false;
    }

    /**
     * @return string
     */
    public function providerName()
    {
        return strtolower($this->accountGateway->gateway->provider);
    }

    /**
     * @return mixed
     */
    protected function invoice()
    {
        return $this->invitation->invoice;
    }

    /**
     * @return mixed
     */
    protected function contact()
    {
        return $this->invitation->contact;
    }

    /**
     * @return mixed
     */
    protected function client()
    {
        return $this->invoice()->client;
    }

    /**
     * @return mixed
     */
    protected function account()
    {
        return $this->client()->account;
    }

    /**
     * @param array $input
     * @param bool $sourceId
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Http\RedirectResponse|\Illuminate\View\View
     * @throws Exception
     */
    public function startPurchase(array $input = null, $sourceId = false)
    {
        $this->input = $input;
        $this->sourceId = $sourceId;

        Session::put('invitation_key', $this->invitation->invitation_key);
        Session::put($this->invitation->id . 'gateway_type', $this->gatewayType);
        Session::put($this->invitation->id . 'payment_ref', $this->invoice()->id . '_' . uniqid());

        $gateway = $this->accountGateway->gateway;

        if ($this->isGatewayType(GATEWAY_TYPE_TOKEN) || $gateway->is_offsite) {
            if (Session::has('error')) {
                Session::reflash();
            } else {
                $this->completeOnsitePurchase();
                Session::flash('message', trans('texts.applied_payment'));
            }

            return redirect()->to('view/' . $this->invitation->invitation_key);
        }

        $data = [
            'details' => ! empty($input['details']) ? json_decode($input['details']) : false,
            'accountGateway' => $this->accountGateway,
            'acceptedCreditCardTypes' => $this->accountGateway->getCreditcardTypes(),
            'gateway' => $gateway,
            'showAddress' => $this->accountGateway->show_address,
            'showBreadcrumbs' => false,
            'url' => 'payment/' . $this->invitation->invitation_key,
            'amount' => $this->invoice()->getRequestedAmount(),
            'invoiceNumber' => $this->invoice()->invoice_number,
            'client' => $this->client(),
            'contact' => $this->invitation->contact,
            'gatewayType' => $this->gatewayType,
            'currencyId' => $this->client()->getCurrencyId(),
            'currencyCode' => $this->client()->getCurrencyCode(),
            'account' => $this->account(),
            'sourceId' => $sourceId,
            'clientFontUrl' => $this->account()->getFontsUrl(),
            'tokenize' => $this->tokenize(),
            'transactionToken' => $this->createTransactionToken(),
        ];

        return view($this->paymentView(), $data);
    }

    /**
     * Check if a custom view exists for this provider
     *
     * @return mixed
     */
    protected function paymentView()
    {
        $file = sprintf('%s/views/payments/%s/%s.blade.php', resource_path(), $this->providerName(), $this->gatewayType);

        if (file_exists($file)) {
            return sprintf('payments.%s/%s', $this->providerName(), $this->gatewayType);
        } else {
            return sprintf('payments.%s', $this->gatewayType);
        }
    }

    /**
     * Check if a custom partial exists for this provider
     *
     * @return bool
     */
    public function partialView()
    {
        $file = sprintf('%s/views/payments/%s/partial.blade.php', resource_path(), $this->providerName());

        if (file_exists($file)) {
            return sprintf('payments.%s.partial', $this->providerName());
        } else {
            return false;
        }
    }

    /**
     * @return array
     */
    public function rules()
    {
        $rules = [];

        if ($this->isGatewayType(GATEWAY_TYPE_CREDIT_CARD)) {

            $rules = array_merge($rules, [
                'first_name' => 'required',
                'last_name' => 'required',
            ]);

            // TODO check this is always true
            if ( ! $this->tokenize()) {
                $rules = array_merge($rules, [
                    'card_number' => 'required',
                    'expiration_month' => 'required',
                    'expiration_year' => 'required',
                    'cvv' => 'required',
                ]);
            }

            if ($this->accountGateway->show_address) {
                $rules = array_merge($rules, [
                    'address1' => 'required',
                    'city' => 'required',
                    'state' => 'required',
                    'postal_code' => 'required',
                    'country_id' => 'required',
                ]);
            }
        }

        return $rules;
    }

    /**
     * @return mixed
     */
    protected function gateway()
    {
        if ($this->gateway) {
            return $this->gateway;
        }

        $this->gateway = Omnipay::create($this->accountGateway->gateway->provider);
        $this->gateway->initialize((array) $this->accountGateway->getConfig());

        return $this->gateway;
    }

    /**
     * @param bool $input
     * @param PaymentMethod $paymentMethod
     *
     * @return Payment|mixed|void
     * @throws Exception
     */
    public function completeOnsitePurchase($input = false, PaymentMethod $paymentMethod = null)
    {
        $this->input = count($input) ? $input : false;
        $gateway = $this->gateway();

        if ($input) {
            $this->updateClient();
        }

        // load or create token
        if ($this->isGatewayType(GATEWAY_TYPE_TOKEN)) {
            if ( ! $paymentMethod) {
                $paymentMethod = PaymentMethod::clientId($this->client()->id)
                    ->wherePublicId($this->sourceId)
                    ->firstOrFail();
            }
        } elseif ($this->shouldCreateToken()) {
            $paymentMethod = $this->createToken();
        }

        if ($this->isTwoStep()) {
            return;
        }

        // prepare and process payment
        $data = $this->paymentDetails($paymentMethod);
        $response = $gateway->purchase($data)->send();
        $this->purchaseResponse = (array) $response->getData();

        // parse the transaction reference
        if ($this->transactionReferenceParam) {
            $ref = $this->purchaseResponse[$this->transactionReferenceParam];
        } else {
            $ref = $response->getTransactionReference();
        }

        // wrap up
        if ($response->isSuccessful() && $ref) {
            $payment = $this->createPayment($ref, $paymentMethod);

            // TODO move this to stripe driver
            if ($this->invitation->invoice->account->account_key == NINJA_ACCOUNT_KEY) {
                Session::flash('trackEventCategory', '/account');
                Session::flash('trackEventAction', '/buy_pro_plan');
                Session::flash('trackEventAmount', $payment->amount);
            }

            return $payment;
        } elseif ($response->isRedirect()) {
            $this->invitation->transaction_reference = $ref;
            $this->invitation->save();
            //Session::put('transaction_reference', $ref);
            Session::save();
            $response->redirect();
        } else {
            throw new Exception($response->getMessage() ?: trans('texts.payment_error'));
        }
    }

    private function updateClient()
    {
        if ( ! $this->isGatewayType(GATEWAY_TYPE_CREDIT_CARD)) {
            return;
        }

        // update the contact info
        if ( ! $this->contact()->getFullName()) {
            $this->contact()->first_name = $this->input['first_name'];
            $this->contact()->last_name = $this->input['last_name'];
        }

        if ( ! $this->contact()->email) {
            $this->contact()->email = $this->input['email'];
        }

        if ($this->contact()->isDirty()) {
            $this->contact()->save();
        }

        if ( ! $this->accountGateway->show_address || ! $this->accountGateway->update_address) {
            return;
        }

        // update the address info
        $client = $this->client();
        $client->address1 = trim($this->input['address1']);
        $client->address2 = trim($this->input['address2']);
        $client->city = trim($this->input['city']);
        $client->state = trim($this->input['state']);
        $client->postal_code = trim($this->input['postal_code']);
        $client->country_id = trim($this->input['country_id']);
        $client->save();
    }

    /**
     * @param PaymentMethod $paymentMethod
     *
     * @return array
     */
    protected function paymentDetails(PaymentMethod $paymentMethod = null)
    {
        $invoice = $this->invoice();
        $completeUrl = url('complete/' . $this->invitation->invitation_key . '/' . $this->gatewayType);

        $data = [
            'amount' => $invoice->getRequestedAmount(),
            'currency' => $invoice->getCurrencyCode(),
            'returnUrl' => $completeUrl,
            'cancelUrl' => $this->invitation->getLink(),
            'description' => trans('texts.' . $invoice->getEntityType()) . " {$invoice->invoice_number}",
            'transactionId' => $invoice->invoice_number,
            'transactionType' => 'Purchase',
            'ip' => Request::ip()
        ];

        if ($paymentMethod) {
            if ($this->customerReferenceParam) {
                $data[$this->customerReferenceParam] = $paymentMethod->account_gateway_token->token;
            }
            $data[$this->sourceReferenceParam] = $paymentMethod->source_reference;
        } elseif ($this->input) {
            $data['card'] = new CreditCard($this->paymentDetailsFromInput($this->input));
        } else {
            $data['card'] = new CreditCard($this->paymentDetailsFromClient());
        }

        return $data;
    }

    /**
     * @param array $input
     *
     * @return array
     */
    private function paymentDetailsFromInput(array $input)
    {
        $client = $this->client();

        $data = [
            'company' => $client->getDisplayName(),
            'firstName' => isset($input['first_name']) ? $input['first_name'] : null,
            'lastName' => isset($input['last_name']) ? $input['last_name'] : null,
            'email' => isset($input['email']) ? $input['email'] : null,
            'number' => isset($input['card_number']) ? $input['card_number'] : null,
            'expiryMonth' => isset($input['expiration_month']) ? $input['expiration_month'] : null,
            'expiryYear' => isset($input['expiration_year']) ? $input['expiration_year'] : null,
        ];

        // allow space until there's a setting to disable
        if (isset($input['cvv']) && $input['cvv'] != ' ') {
            $data['cvv'] = $input['cvv'];
        }

        if (isset($input['address1'])) {
            // TODO use cache instead
            $country = Country::find($input['country_id']);

            $data = array_merge($data, [
                'billingAddress1' => $input['address1'],
                'billingAddress2' => $input['address2'],
                'billingCity' => $input['city'],
                'billingState' => $input['state'],
                'billingPostcode' => $input['postal_code'],
                'billingCountry' => $country->iso_3166_2,
                'shippingAddress1' => $input['address1'],
                'shippingAddress2' => $input['address2'],
                'shippingCity' => $input['city'],
                'shippingState' => $input['state'],
                'shippingPostcode' => $input['postal_code'],
                'shippingCountry' => $country->iso_3166_2
            ]);
        }

        return $data;
    }

    /**
     * @return array
     */
    public function paymentDetailsFromClient()
    {
        $client = $this->client();
        $contact = $this->invitation->contact ?: $client->contacts()->first();

        return [
            'email' => $contact->email,
            'company' => $client->getDisplayName(),
            'firstName' => $contact->first_name,
            'lastName' => $contact->last_name,
            'billingAddress1' => $client->address1,
            'billingAddress2' => $client->address2,
            'billingCity' => $client->city,
            'billingPostcode' => $client->postal_code,
            'billingState' => $client->state,
            'billingCountry' => $client->country ? $client->country->iso_3166_2 : '',
            'billingPhone' => $contact->phone,
            'shippingAddress1' => $client->address1,
            'shippingAddress2' => $client->address2,
            'shippingCity' => $client->city,
            'shippingPostcode' => $client->postal_code,
            'shippingState' => $client->state,
            'shippingCountry' => $client->country ? $client->country->iso_3166_2 : '',
            'shippingPhone' => $contact->phone,
        ];
    }

    /**
     * @return bool
     */
    protected function shouldCreateToken()
    {
        if ($this->isGatewayType(GATEWAY_TYPE_BANK_TRANSFER)) {
            return true;
        }

        if ( ! $this->handles(GATEWAY_TYPE_TOKEN)) {
            return false;
        }

        if ($this->account()->token_billing_type_id == TOKEN_BILLING_ALWAYS) {
            return true;
        }

        return boolval(array_get($this->input, 'token_billing'));
    }

    /**
     * @param bool $clientId
     *
     * @return null
     */
    public function customer($clientId = false)
    {
        if ($this->customer) {
            return $this->customer;
        }

        if ( ! $clientId) {
            $clientId = $this->client()->id;
        }

        $this->customer = AccountGatewayToken::clientAndGateway($clientId, $this->accountGateway->id)
                            ->with('payment_methods')
                            ->first();

        if ($this->customer) {
            $this->customer = $this->checkCustomerExists($this->customer) ? $this->customer : null;
        }

        return $this->customer;
    }

    /**
     * @param $customer
     *
     * @return bool
     */
    protected function checkCustomerExists($customer)
    {
        return true;
    }

    /**
     * @param $client
     * @param $publicId
     * @param $amount1
     * @param $amount2
     *
     * @throws Exception
     */
    public function verifyBankAccount($client, $publicId, $amount1, $amount2)
    {
        throw new Exception('verifyBankAccount not implemented');
    }

    /**
     * @param PaymentMethod $paymentMethod
     * @throws Exception
     */
    public function removePaymentMethod(PaymentMethod $paymentMethod)
    {
        $paymentMethod->delete();
    }

    /**
     * Some gateways (ie, Checkout.com and Braintree) require generating a token before paying for the invoice
     *
     * @return null
     */
    public function createTransactionToken()
    {
        return null;
    }

    /**
     * @return PaymentMethod
     */
    public function createToken()
    {
        $account = $this->account();

        if ( ! $customer = $this->customer()) {
            $customer = new AccountGatewayToken();
            $customer->account_id = $account->id;
            $customer->contact_id = $this->invitation->contact_id;
            $customer->account_gateway_id = $this->accountGateway->id;
            $customer->client_id = $this->client()->id;
            $customer = $this->creatingCustomer($customer);
            $customer->save();
        }

        $paymentMethod = $this->createPaymentMethod($customer);

        if ($paymentMethod && ! $customer->default_payment_method_id) {
            $customer->default_payment_method_id = $paymentMethod->id;
            $customer->save();
        }

        return $paymentMethod;
    }

    /**
     * @param $customer
     *
     * @return mixed
     */
    protected function creatingCustomer($customer)
    {
        return $customer;
    }

    /**
     * @param $customer
     *
     * @return PaymentMethod
     */
    public function createPaymentMethod($customer)
    {
        /** @var PaymentMethod $paymentMethod */
        $paymentMethod = PaymentMethod::createNew($this->invitation);
        $paymentMethod->contact_id = $this->contact()->id;
        $paymentMethod->ip = Request::ip();
        $paymentMethod->account_gateway_token_id = $customer->id;
        $paymentMethod->setRelation('account_gateway_token', $customer);
        $paymentMethod = $this->creatingPaymentMethod($paymentMethod);

        if ($paymentMethod) {
            $paymentMethod->save();
        }

        return $paymentMethod;
    }

    /**
     * @param PaymentMethod $paymentMethod
     *
     * @return PaymentMethod
     */
    protected function creatingPaymentMethod(PaymentMethod $paymentMethod)
    {
        return $paymentMethod;
    }

    public function deleteToken()
    {

    }

    /**
     * @param bool $ref
     * @param PaymentMethod|null $paymentMethod
     *
     * @return Payment|mixed
     */
    public function createPayment($ref = false, PaymentMethod $paymentMethod = null)
    {
        $invitation = $this->invitation;
        $invoice = $this->invoice();

        $payment = Payment::createNew($invitation);
        $payment->invitation_id = $invitation->id;
        $payment->account_gateway_id = $this->accountGateway->id;
        $payment->invoice_id = $invoice->id;
        $payment->amount = $invoice->getRequestedAmount();
        $payment->client_id = $invoice->client_id;
        $payment->contact_id = $invitation->contact_id;
        $payment->transaction_reference = $ref;
        $payment->payment_date = date_create()->format('Y-m-d');
        $payment->ip = Request::ip();

        $payment = $this->creatingPayment($payment, $paymentMethod);

        if ($paymentMethod) {
            $payment->last4 = $paymentMethod->last4;
            $payment->expiration = $paymentMethod->expiration;
            $payment->routing_number = $paymentMethod->routing_number;
            $payment->payment_type_id = $paymentMethod->payment_type_id;
            $payment->email = $paymentMethod->email;
            $payment->bank_name = $paymentMethod->bank_name;
            $payment->payment_method_id = $paymentMethod->id;
        }

        $payment->save();

        // TODO move this code
        // enable pro plan for hosted users
        if ($invoice->account->account_key == NINJA_ACCOUNT_KEY) {
            foreach ($invoice->invoice_items as $invoice_item) {
                // Hacky, but invoices don't have meta fields to allow us to store this easily
                if (1 == preg_match('/^Plan - (.+) \((.+)\)$/', $invoice_item->product_key, $matches)) {
                    $plan = strtolower($matches[1]);
                    $term = strtolower($matches[2]);
                    $price = $invoice_item->cost;
                    if ($plan == PLAN_ENTERPRISE) {
                        preg_match('/###[\d] [\w]* (\d*)/', $invoice_item->notes, $matches);
                        $numUsers = $matches[1];
                    } else {
                        $numUsers = 1;
                    }
                }
            }

            if (!empty($plan)) {
                $account = Account::with('users')->find($invoice->client->public_id);
                $company = $account->company;

                if(
                    $company->plan != $plan
                    || DateTime::createFromFormat('Y-m-d', $account->company->plan_expires) >= date_create('-7 days')
                ) {
                    // Either this is a different plan, or the subscription expired more than a week ago
                    // Reset any grandfathering
                    $company->plan_started = date_create()->format('Y-m-d');
                }

                if (
                    $company->plan == $plan
                    && $company->plan_term == $term
                    && DateTime::createFromFormat('Y-m-d', $company->plan_expires) >= date_create()
                ) {
                    // This is a renewal; mark it paid as of when this term expires
                    $company->plan_paid = $company->plan_expires;
                } else {
                    $company->plan_paid = date_create()->format('Y-m-d');
                }

                $company->payment_id = $payment->id;
                $company->plan = $plan;
                $company->plan_term = $term;
                $company->plan_price = $price;
                $company->num_users = $numUsers;
                $company->plan_expires = DateTime::createFromFormat('Y-m-d', $account->company->plan_paid)
                    ->modify($term == PLAN_TERM_MONTHLY ? '+1 month' : '+1 year')->format('Y-m-d');

                $company->save();
            }
        }

        return $payment;
    }

    /**
     * @param Payment $payment
     * @param $paymentMethod
     *
     * @return Payment
     */
    protected function creatingPayment(Payment $payment, PaymentMethod $paymentMethod)
    {
        return $payment;
    }

    /**
     * @param Payment $payment
     * @param int $amount
     *
     * @return bool
     */
    public function refundPayment(Payment $payment, $amount = 0)
    {
        if ($amount) {
            $amount = min($amount, $payment->getCompletedAmount());
        } else {
            $amount = $payment->getCompletedAmount();
        }

        if ( ! $amount) {
            return false;
        }

        if ($payment->payment_type_id == PAYMENT_TYPE_CREDIT) {
            return $payment->recordRefund($amount);
        }

        $details = $this->refundDetails($payment, $amount);
        $response = $this->gateway()->refund($details)->send();

        if ($response->isSuccessful()) {
            return $payment->recordRefund($amount);
        } elseif ($this->attemptVoidPayment($response, $payment, $amount)) {
            $details = ['transactionReference' => $payment->transaction_reference];
            $response = $this->gateway->void($details)->send();
            if ($response->isSuccessful()) {
                return $payment->markVoided();
            }
        }

        return false;
    }

    /**
     * @param Payment $payment
     * @param $amount
     *
     * @return array
     */
    protected function refundDetails(Payment $payment, $amount)
    {
        return [
            'amount' => $amount,
            'transactionReference' => $payment->transaction_reference,
        ];
    }

    /**
     * @param $response
     * @param Payment $payment
     * @param $amount
     *
     * @return bool
     */
    protected function attemptVoidPayment($response, Payment $payment, $amount)
    {
        // Partial refund not allowed for unsettled transactions
        return $amount == $payment->amount;
    }

    protected function createLocalPayment($payment)
    {
        return $payment;
    }

    /**
     * @param $input
     *
     * @return bool|mixed
     * @throws Exception
     */
    public function completeOffsitePurchase(array $input)
    {
        $this->input = $input;
        $ref = array_get($this->input, 'token') ?: $this->invitation->transaction_reference;

        if (method_exists($this->gateway(), 'completePurchase')) {

            $details = $this->paymentDetails();
            $response = $this->gateway()->completePurchase($details)->send();
            $ref = $response->getTransactionReference() ?: $ref;

            if ($response->isCancelled()) {
                return false;
            } elseif ( ! $response->isSuccessful()) {
                throw new Exception($response->getMessage());
            }
        }

        // check invoice still has balance
        if ( ! floatval($this->invoice()->balance)) {
            throw new Exception(trans('texts.payment_error_code', ['code' => 'NB']));
        }

        // check this isn't a duplicate transaction reference
        if (Payment::whereAccountId($this->invitation->account_id)
                ->whereTransactionReference($ref)
                ->first()) {
            throw new Exception(trans('texts.payment_error_code', ['code' => 'DT']));
        }

        return $this->createPayment($ref);
    }

    /**
     * @return array
     */
    public function tokenLinks()
    {
        if ( ! $this->customer()) {
            return [];
        }

        $paymentMethods = $this->customer()->payment_methods;
        $links = [];

        foreach ($paymentMethods as $paymentMethod) {
            if ($paymentMethod->payment_type_id == PAYMENT_TYPE_ACH && $paymentMethod->status != PAYMENT_METHOD_STATUS_VERIFIED) {
                continue;
            }

            $url = URL::to("/payment/{$this->invitation->invitation_key}/token/".$paymentMethod->public_id);

            if ($paymentMethod->payment_type_id == PAYMENT_TYPE_ACH) {
                if ($paymentMethod->bank_name) {
                    $label = $paymentMethod->bank_name;
                } else {
                    $label = trans('texts.use_bank_on_file');
                }
            } elseif ($paymentMethod->payment_type_id == PAYMENT_TYPE_PAYPAL) {
                $label = 'PayPal: ' . $paymentMethod->email;
            } else {
                $label = trans('texts.use_card_on_file');
            }

            $links[] = [
                'url' => $url,
                'label' => $label,
            ];
        }

        return $links;
    }

    /**
     * @return array
     */
    public function paymentLinks()
    {
        $links = [];

        foreach ($this->gatewayTypes() as $gatewayType) {
            if ($gatewayType === GATEWAY_TYPE_TOKEN) {
                continue;
            }

            $links[] = [
                'url' => $this->paymentUrl($gatewayType),
                'label' => trans("texts.{$gatewayType}")
            ];
        }

        return $links;
    }

    /**
     * @param $gatewayType
     *
     * @return string
     */
    protected function paymentUrl($gatewayType)
    {
        $account = $this->account();
        $url = URL::to("/payment/{$this->invitation->invitation_key}/{$gatewayType}");

        // PayPal doesn't allow being run in an iframe so we need to open in new tab
        if ($gatewayType === GATEWAY_TYPE_PAYPAL) {
            $url .= '#braintree_paypal';

            if ($account->iframe_url) {
                return 'javascript:window.open("' . $url . '", "_blank")';
            }
        }

        return $url;
    }

    /**
     * @param $cardName
     *
     * @return mixed
     */
    protected function parseCardType($cardName) {
        $cardTypes = [
            'visa' => PAYMENT_TYPE_VISA,
            'americanexpress' => PAYMENT_TYPE_AMERICAN_EXPRESS,
            'amex' => PAYMENT_TYPE_AMERICAN_EXPRESS,
            'mastercard' => PAYMENT_TYPE_MASTERCARD,
            'discover' => PAYMENT_TYPE_DISCOVER,
            'jcb' => PAYMENT_TYPE_JCB,
            'dinersclub' => PAYMENT_TYPE_DINERS,
            'carteblanche' => PAYMENT_TYPE_CARTE_BLANCHE,
            'chinaunionpay' => PAYMENT_TYPE_UNIONPAY,
            'unionpay' => PAYMENT_TYPE_UNIONPAY,
            'laser' => PAYMENT_TYPE_LASER,
            'maestro' => PAYMENT_TYPE_MAESTRO,
            'solo' => PAYMENT_TYPE_SOLO,
            'switch' => PAYMENT_TYPE_SWITCH
        ];

        $cardName = strtolower(str_replace([' ', '-', '_'], '', $cardName));

        if (empty($cardTypes[$cardName]) && 1 == preg_match('/^('.implode('|', array_keys($cardTypes)).')/', $cardName, $matches)) {
            // Some gateways return extra stuff after the card name
            $cardName = $matches[1];
        }

        if (!empty($cardTypes[$cardName])) {
            return $cardTypes[$cardName];
        } else {
            return PAYMENT_TYPE_CREDIT_CARD_OTHER;
        }
    }

    /**
     * @param $input
     *
     * @throws Exception
     */
    public function handleWebHook($input)
    {
        throw new Exception('Unsupported gateway');
    }
}

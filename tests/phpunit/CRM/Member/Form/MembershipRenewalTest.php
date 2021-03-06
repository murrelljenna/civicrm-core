<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

use Civi\Api4\Contact;

/**
 *  Test CRM_Member_Form_Membership functions.
 *
 * @package   CiviCRM
 * @group headless
 */
class CRM_Member_Form_MembershipRenewalTest extends CiviUnitTestCase {

  protected $_individualId;
  protected $_contribution;
  protected $_financialTypeId = 1;
  protected $_entity = 'Membership';
  protected $_params;
  protected $_paymentProcessorID;

  /**
   * Membership type ID for annual fixed membership.
   *
   * @var int
   */
  protected $membershipTypeAnnualFixedID;

  /**
   * Parameters to create payment processor.
   *
   * @var array
   */
  protected $_processorParams = [];

  /**
   * ID of created membership.
   *
   * @var int
   */
  protected $_membershipID;

  /**
   * Payment instrument mapping.
   *
   * @var array
   */
  protected $paymentInstruments = [];


  /**
   * @var CiviMailUtils
   */
  protected $mut;

  /**
   * @var int
   */
  private $financialTypeID;

  /**
   * Test setup for every test.
   *
   * Connect to the database, truncate the tables that will be used
   * and redirect stdin to a temporary file.
   *
   * @throws \CRM_Core_Exception
   */
  public function setUp() {
    parent::setUp();

    $this->_individualId = $this->individualCreate();
    $this->_paymentProcessorID = $this->processorCreate();
    $this->financialTypeID = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Member Dues');
    $this->ids['contact']['organization'] = $this->organizationCreate();
    $this->membershipTypeAnnualFixedID = $this->callAPISuccess('membership_type', 'create', [
      'domain_id' => 1,
      'name' => 'AnnualFixed',
      'member_of_contact_id' => $this->ids['contact']['organization'],
      'duration_unit' => 'year',
      'duration_interval' => 1,
      'period_type' => 'fixed',
      'fixed_period_start_day' => '101',
      'fixed_period_rollover_day' => '1231',
      'relationship_type_id' => 20,
      'min_fee' => 100,
      'financial_type_id' => $this->financialTypeID,
      'max_related' => 10,
    ])['id'];

    $this->_membershipID = $this->callAPISuccess('Membership', 'create', [
      'contact_id' => $this->_individualId,
      'membership_type_id' => $this->membershipTypeAnnualFixedID,
      'join_date' => '2020-04-13',
      'source' => 'original_source',
    ])['id'];

    $this->paymentInstruments = $this->callAPISuccess('Contribution', 'getoptions', ['field' => 'payment_instrument_id'])['values'];
  }

  /**
   * Clean up after each test.
   *
   * @throws \CRM_Core_Exception
   */
  public function tearDown() {
    $this->validateAllPayments();
    $this->validateAllContributions();
    $this->quickCleanUpFinancialEntities();
    $this->quickCleanup(
      [
        'civicrm_relationship',
        'civicrm_uf_match',
        'civicrm_address',
      ]
    );
    foreach ($this->ids['contact'] as $contactID) {
      $this->callAPISuccess('contact', 'delete', ['id' => $contactID, 'skip_undelete' => TRUE]);
    }
  }

  /**
   * Test the submit function of the membership form.
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function testSubmit() {
    $form = $this->getForm();
    $loggedInUserID = $this->createLoggedInUser();
    $loggedInUserDisplayName = Contact::get()->addWhere('id', '=', $loggedInUserID)->addSelect('display_name')->execute()->first()['display_name'];
    $params = $this->getBaseSubmitParams();
    $form->_contactID = $this->_individualId;

    $form->testSubmit(array_merge($params, ['total_amount' => 50]));
    $form->setRenewalMessage();
    $membership = $this->callAPISuccessGetSingle('Membership', ['contact_id' => $this->_individualId]);
    $this->callAPISuccessGetCount('ContributionRecur', ['contact_id' => $this->_individualId], 0);
    $contribution = $this->callAPISuccessGetSingle('Contribution', [
      'contact_id' => $this->_individualId,
      'is_test' => TRUE,
    ]);
    $expectedContributionSource = 'AnnualFixed Membership: Offline membership renewal (by ' . $loggedInUserDisplayName . ')';
    $this->assertEquals($expectedContributionSource, $contribution['contribution_source']);

    $this->callAPISuccessGetCount('LineItem', [
      'entity_id' => $membership['id'],
      'entity_table' => 'civicrm_membership',
      'contribution_id' => $contribution['id'],
    ], 1);
    $this->_checkFinancialRecords([
      'id' => $contribution['id'],
      'total_amount' => 50,
      'financial_account_id' => 2,
      'payment_instrument_id' => $this->callAPISuccessGetValue('PaymentProcessor', [
        'id' => $this->_paymentProcessorID,
        'return' => 'payment_instrument_id',
      ]),
    ], 'online');
    $this->assertEquals([
      [
        'text' => 'AnnualFixed membership for Mr. Anthony Anderson II has been renewed.',
        'title' => 'Complete',
        'type' => 'success',
        'options' => NULL,
      ],
    ], CRM_Core_Session::singleton()->getStatus());
  }

  /**
   * Test submitting with tax enabled.
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function testSubmitWithTax() {
    $this->enableTaxAndInvoicing();
    $this->relationForFinancialTypeWithFinancialAccount($this->financialTypeID);
    $form = $this->getForm();
    $form->testSubmit(array_merge($this->getBaseSubmitParams(), [
      'total_amount' => '50.00',
    ]));
    $contribution = $this->callAPISuccessGetSingle('Contribution', ['contact_id' => $this->_individualId, 'is_test' => TRUE, 'return' => ['total_amount', 'tax_amount']]);
    $this->assertEquals(50, $contribution['total_amount']);
    $this->assertEquals(4.55, $contribution['tax_amount']);
  }

  /**
   * Test the submit function of the membership form.
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function testSubmitChangeType() {
    $form = $this->getForm();
    $this->createLoggedInUser();
    $membershipBefore = $this->callAPISuccessGetSingle('Membership', ['contact_id' => $this->_individualId]);
    $newMembershipTypeID = $this->callAPISuccess('MembershipType', 'create', [
      'name' => 'Monthly',
      'member_of_contact_id' => $this->ids['contact']['organization'],
      'financial_type_id' => $this->financialTypeID,
      'duration_unit' => 'month',
      'duration_interval' => 2,
      'period_type' => 'rolling',
    ])['id'];
    $form->_contactID = $this->_individualId;

    $form->testSubmit(array_merge($this->getBaseSubmitParams(), ['membership_type_id' => [$this->ids['contact']['organization'], $newMembershipTypeID]]));
    $membership = $this->callAPISuccessGetSingle('Membership', ['contact_id' => $this->_individualId]);
    $this->assertEquals($newMembershipTypeID, $membership['membership_type_id']);
    // The date (31 Dec this year) should be progressed by 2 months to 28 Dec next year.
    $this->assertEquals(date('Y', strtotime($membershipBefore['end_date'])) + 1 . '-02-28', $membership['end_date']);
  }

  /**
   * Test the submit function of the membership form.
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function testSubmitRecur() {
    $form = $this->getForm();

    $this->callAPISuccess('MembershipType', 'create', [
      'id' => $this->membershipTypeAnnualFixedID,
      'duration_unit' => 'month',
      'duration_interval' => 1,
      'auto_renew' => TRUE,
    ]);
    $form->preProcess();
    $this->createLoggedInUser();
    $params = [
      'cid' => $this->_individualId,
      'price_set_id' => 0,
      'join_date' => date('m/d/Y'),
      'start_date' => '',
      'end_date' => '',
      'campaign_id' => '',
      // This format reflects the 23 being the organisation & the 25 being the type.
      'membership_type_id' => [$this->ids['contact']['organization'], $this->membershipTypeAnnualFixedID],
      'auto_renew' => '1',
      'is_recur' => 1,
      'num_terms' => '1',
      'source' => '',
      'total_amount' => '77.00',
      //Member dues, see data.xml
      'financial_type_id' => '2',
      'from_email_address' => '"Demonstrators Anonymous" <info@example.org>',
      'receipt_text' => 'Thank you text',
      'payment_processor_id' => $this->_paymentProcessorID,
      'credit_card_number' => '4111111111111111',
      'cvv2' => '123',
      'credit_card_exp_date' => [
        'M' => '9',
        'Y' => date('Y') + 1,
      ],
      'credit_card_type' => 'Visa',
      'billing_first_name' => 'Test',
      'billing_middlename' => 'Last',
      'billing_street_address-5' => '10 Test St',
      'billing_city-5' => 'Test',
      'billing_state_province_id-5' => '1003',
      'billing_postal_code-5' => '90210',
      'billing_country_id-5' => '1228',
      'send_receipt' => 1,
    ];
    $form->_mode = 'test';
    $form->_contactID = $this->_individualId;

    $form->testSubmit($params);
    $membership = $this->callAPISuccessGetSingle('Membership', ['contact_id' => $this->_individualId]);
    $contributionRecur = $this->callAPISuccessGetSingle('ContributionRecur', ['contact_id' => $this->_individualId]);
    $this->assertEquals(1, $contributionRecur['is_email_receipt']);
    $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($contributionRecur['modified_date'])));
    $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($contributionRecur['modified_date'])));
    $this->assertNotEmpty($contributionRecur['invoice_id']);
    $this->assertEquals(CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id',
      'Pending'), $contributionRecur['contribution_status_id']);
    $this->assertEquals($this->callAPISuccessGetValue('PaymentProcessor', [
      'id' => $this->_paymentProcessorID,
      'return' => 'payment_instrument_id',
    ]), $contributionRecur['payment_instrument_id']);

    $contribution = $this->callAPISuccess('Contribution', 'getsingle', [
      'contact_id' => $this->_individualId,
      'is_test' => TRUE,
    ]);

    $this->assertEquals($this->callAPISuccessGetValue('PaymentProcessor', [
      'id' => $this->_paymentProcessorID,
      'return' => 'payment_instrument_id',
    ]), $contribution['payment_instrument_id']);
    $this->assertEquals($contributionRecur['id'], $contribution['contribution_recur_id']);

    $this->callAPISuccessGetCount('LineItem', [
      'entity_id' => $membership['id'],
      'entity_table' => 'civicrm_membership',
      'contribution_id' => $contribution['id'],
    ], 1);

    $this->callAPISuccessGetSingle('address', [
      'contact_id' => $this->_individualId,
      'street_address' => '10 Test St',
      'postal_code' => 90210,
    ]);
  }

  /**
   * Test the submit function of the membership form.
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function testSubmitRecurCompleteInstant() {
    $form = $this->getForm();
    /** @var \CRM_Core_Payment_Dummy $processor */
    $processor = Civi\Payment\System::singleton()->getById($this->_paymentProcessorID);
    $processor->setDoDirectPaymentResult([
      'payment_status_id' => 1,
      'trxn_id' => 'kettles boil water',
      'fee_amount' => .29,
    ]);

    $this->callAPISuccess('MembershipType', 'create', [
      'id' => $this->membershipTypeAnnualFixedID,
      'duration_unit' => 'month',
      'duration_interval' => 1,
      'auto_renew' => TRUE,
    ]);
    $this->createLoggedInUser();
    $form->preProcess();

    $form->_contactID = $this->_individualId;
    $params = array_merge($this->getBaseSubmitParams(), ['is_recur' => 1, 'auto_renew' => '1']);
    $form->_mode = 'test';

    $form->testSubmit($params);
    $membership = $this->callAPISuccessGetSingle('Membership', ['contact_id' => $this->_individualId]);
    $this->assertEquals('2020-04-13', $membership['join_date']);
    $this->assertEquals(date('Y-01-01'), $membership['start_date']);
    $nextYear = date('Y') + 1;
    $this->assertEquals(date($nextYear . '-01-31'), $membership['end_date']);
    $expectedStatus = (strtotime(date('Y-07-14')) > time()) ? 'New' : 'Current';
    $this->assertEquals(CRM_Core_PseudoConstant::getKey('CRM_Member_BAO_Membership', 'status_id', $expectedStatus), $membership['status_id']);
    $this->assertNotEmpty($membership['contribution_recur_id']);
    $this->assertNotEmpty('original_source', $membership['source']);

    $log = $this->callAPISuccessGetSingle('MembershipLog', ['membership_id' => $membership['id'], 'options' => ['limit' => 1, 'sort' => 'id DESC']]);
    $this->assertEquals(date($nextYear . '-01-01'), $log['start_date']);
    $this->assertEquals(date($nextYear . '-01-31'), $log['end_date']);
    $this->assertEquals(date('Y-m-d'), $log['modified_date']);

    $contributionRecur = $this->callAPISuccessGetSingle('ContributionRecur', ['contact_id' => $this->_individualId]);
    $this->assertEquals($contributionRecur['id'], $membership['contribution_recur_id']);
    $this->assertEquals(0, $contributionRecur['is_email_receipt']);
    $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($contributionRecur['modified_date'])));
    $this->assertNotEmpty($contributionRecur['invoice_id']);
    // @todo fix this part!
    /*
    $this->assertEquals(CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id',
    'In Progress'), $contributionRecur['contribution_status_id']);
    $this->assertNotEmpty($contributionRecur['next_sched_contribution_date']);
     */
    $paymentInstrumentID = $this->callAPISuccessGetValue('PaymentProcessor', [
      'id' => $this->_paymentProcessorID,
      'return' => 'payment_instrument_id',
    ]);
    $this->assertEquals($paymentInstrumentID, $contributionRecur['payment_instrument_id']);

    $contribution = $this->callAPISuccess('Contribution', 'getsingle', [
      'contact_id' => $this->_individualId,
      'is_test' => TRUE,
    ]);
    $this->assertEquals($paymentInstrumentID, $contribution['payment_instrument_id']);

    $this->assertEquals('kettles boil water', $contribution['trxn_id']);
    $this->assertEquals(.29, $contribution['fee_amount']);
    $this->assertEquals(7800.90, $contribution['total_amount']);
    $this->assertEquals(7800.61, $contribution['net_amount']);

    $this->callAPISuccessGetCount('LineItem', [
      'entity_id' => $membership['id'],
      'entity_table' => 'civicrm_membership',
      'contribution_id' => $contribution['id'],
    ], 1);
  }

  /**
   * Test the submit function of the membership form.
   *
   * @param string $thousandSeparator
   *
   * @dataProvider getThousandSeparators
   *
   * @throws \CiviCRM_API3_Exception
   * @throws \CRM_Core_Exception
   */
  public function testSubmitRecurCompleteInstantWithMail($thousandSeparator) {
    $this->setCurrencySeparators($thousandSeparator);
    $form = $this->getForm();
    $this->mut = new CiviMailUtils($this, TRUE);
    /** @var \CRM_Core_Payment_Dummy $processor */
    $processor = Civi\Payment\System::singleton()->getById($this->_paymentProcessorID);
    $processor->setDoDirectPaymentResult([
      'payment_status_id' => 1,
      'trxn_id' => 'kettles boil water',
      'fee_amount' => .29,
    ]);

    $this->callAPISuccess('MembershipType', 'create', [
      'id' => $this->membershipTypeAnnualFixedID,
      'duration_unit' => 'month',
      'duration_interval' => 1,
      'auto_renew' => TRUE,
    ]);
    $this->createLoggedInUser();
    $form->preProcess();

    $form->_contactID = $this->_individualId;
    $form->_mode = 'test';

    $form->testSubmit(array_merge($this->getBaseSubmitParams(), ['is_recur' => 1, 'send_receipt' => 1, 'auto_renew' => 1]));
    $contributionRecur = $this->callAPISuccessGetSingle('ContributionRecur', ['contact_id' => $this->_individualId]);
    $this->assertEquals(1, $contributionRecur['is_email_receipt']);
    $this->mut->checkMailLog([
      '$ ' . $this->formatMoneyInput(7800.90),
    ]);
    $this->mut->stop();
    $this->setCurrencySeparators(',');
  }

  /**
   * Test the submit function of the membership form.
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function testSubmitPayLater() {
    $form = $this->getForm(NULL);
    $this->createLoggedInUser();
    $originalMembership = $this->callAPISuccessGetSingle('membership', []);
    $params = [
      'cid' => $this->_individualId,
      'join_date' => date('m/d/Y'),
      'start_date' => '',
      'end_date' => '',
      // This format reflects the 23 being the organisation & the 25 being the type.
      'membership_type_id' => [$this->ids['contact']['organization'], $this->membershipTypeAnnualFixedID],
      'auto_renew' => '0',
      'num_terms' => '2',
      'total_amount' => '50.00',
      //Member dues, see data.xml
      'financial_type_id' => '2',
      'payment_instrument_id' => 4,
      'from_email_address' => '"Demonstrators Anonymous" <info@example.org>',
      'receipt_text_signup' => 'Thank you text',
      'payment_processor_id' => $this->_paymentProcessorID,
      'record_contribution' => TRUE,
      'trxn_id' => 777,
      'contribution_status_id' => 2,
    ];
    $form->_contactID = $this->_individualId;

    $form->testSubmit($params);
    $membership = $this->callAPISuccessGetSingle('Membership', ['contact_id' => $this->_individualId]);
    $this->assertEquals(strtotime($membership['end_date']), strtotime($originalMembership['end_date']));
    $contribution = $this->callAPISuccessGetSingle('Contribution', [
      'contact_id' => $this->_individualId,
      'contribution_status_id' => 2,
      'return' => ['tax_amount', 'trxn_id'],
    ]);
    $this->assertEquals($contribution['trxn_id'], 777);
    $this->assertEquals(NULL, $contribution['tax_amount']);

    $this->callAPISuccessGetCount('LineItem', [
      'entity_id' => $membership['id'],
      'entity_table' => 'civicrm_membership',
      'contribution_id' => $contribution['id'],
    ], 1);
  }

  /**
   * Test the submit function of the membership form.
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function testSubmitPayLaterWithBilling() {
    $form = $this->getForm(NULL);
    $this->createLoggedInUser();
    $originalMembership = $this->callAPISuccessGetSingle('membership', []);
    $params = [
      'cid' => $this->_individualId,
      'start_date' => '',
      'end_date' => '',
      // This format reflects the first value being the organisation & the second being the type.
      'membership_type_id' => [$this->ids['contact']['organization'], $this->membershipTypeAnnualFixedID],
      'auto_renew' => '0',
      'num_terms' => '2',
      'total_amount' => '50.00',
      //Member dues, see data.xml
      'financial_type_id' => '2',
      'payment_instrument_id' => 4,
      'from_email_address' => '"Demonstrators Anonymous" <info@example.org>',
      'receipt_text_signup' => 'Thank you text',
      'payment_processor_id' => $this->_paymentProcessorID,
      'record_contribution' => TRUE,
      'trxn_id' => 777,
      'contribution_status_id' => 2,
      'billing_first_name' => 'Test',
      'billing_middle_name' => 'Last',
      'billing_street_address-5' => '10 Test St',
      'billing_city-5' => 'Test',
      'billing_state_province_id-5' => '1003',
      'billing_postal_code-5' => '90210',
      'billing_country_id-5' => '1228',
    ];
    $form->_contactID = $this->_individualId;

    $form->testSubmit($params);
    $membership = $this->callAPISuccessGetSingle('Membership', ['contact_id' => $this->_individualId]);
    $this->assertEquals(strtotime($membership['end_date']), strtotime($originalMembership['end_date']));
    $this->assertEquals(10, $membership['max_related']);

    $contribution = $this->callAPISuccessGetSingle('Contribution', [
      'contact_id' => $this->_individualId,
      'contribution_status_id' => 2,
    ]);
    $this->assertEquals($contribution['trxn_id'], 777);

    $this->callAPISuccessGetCount('LineItem', [
      'entity_id' => $membership['id'],
      'entity_table' => 'civicrm_membership',
      'contribution_id' => $contribution['id'],
    ], 1);
    $this->callAPISuccessGetSingle('address', [
      'contact_id' => $this->_individualId,
      'street_address' => '10 Test St',
      'postal_code' => 90210,
    ]);
  }

  /**
   * Test the submit function of the membership form.
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function testSubmitComplete() {
    $form = $this->getForm(NULL);
    $this->createLoggedInUser();
    $originalMembership = $this->callAPISuccessGetSingle('membership', []);
    $params = [
      'cid' => $this->_individualId,
      'join_date' => date('m/d/Y'),
      'start_date' => '',
      'end_date' => '',
      // This format reflects the 23 being the organisation & the 25 being the type.
      'membership_type_id' => [$this->ids['contact']['organization'], $this->membershipTypeAnnualFixedID],
      'auto_renew' => '0',
      'num_terms' => '2',
      'total_amount' => '50.00',
      //Member dues, see data.xml
      'financial_type_id' => '2',
      'payment_instrument_id' => 4,
      'from_email_address' => '"Demonstrators Anonymous" <info@example.org>',
      'receipt_text_signup' => 'Thank you text',
      'payment_processor_id' => $this->_paymentProcessorID,
      'record_contribution' => TRUE,
      'trxn_id' => 777,
      'contribution_status_id' => 1,
      'fee_amount' => .5,
    ];
    $form->_contactID = $this->_individualId;

    $form->testSubmit($params);
    $membership = $this->callAPISuccessGetSingle('Membership', ['contact_id' => $this->_individualId]);
    $this->assertEquals(strtotime($membership['end_date']), strtotime('+ 2 years',
      strtotime($originalMembership['end_date'])));
    $contribution = $this->callAPISuccessGetSingle('Contribution', [
      'contact_id' => $this->_individualId,
      'contribution_status_id' => 1,
    ]);

    $this->assertEquals($contribution['trxn_id'], 777);
    $this->assertEquals(.5, $contribution['fee_amount']);
    $this->callAPISuccessGetCount('LineItem', [
      'entity_id' => $membership['id'],
      'entity_table' => 'civicrm_membership',
      'contribution_id' => $contribution['id'],
    ], 1);
  }

  /**
   * Get a membership form object.
   *
   * We need to instantiate the form to run preprocess, which means we have to trick it about the request method.
   *
   * @param string $mode
   *
   * @return \CRM_Member_Form_MembershipRenewal
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  protected function getForm($mode = 'test') {
    $form = new CRM_Member_Form_MembershipRenewal();
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $form->controller = new CRM_Core_Controller();
    $form->_bltID = 5;
    $form->_mode = $mode;
    $form->setEntityId($this->_membershipID);
    $form->preProcess();
    return $form;
  }

  /**
   * Get some re-usable parameters for the submit function.
   *
   * @return array
   */
  protected function getBaseSubmitParams() {
    return [
      'cid' => $this->_individualId,
      // This format reflects the key being the organisation & the value being the type.
      'membership_type_id' => [$this->ids['contact']['organization'], $this->membershipTypeAnnualFixedID],
      'num_terms' => '1',
      'total_amount' => $this->formatMoneyInput('7800.90'),
      //Member dues, see data.xml
      'financial_type_id' => '2',
      'from_email_address' => '"Demonstrators Anonymous" <info@example.org>',
      'receipt_text' => 'Thank you text',
      'payment_processor_id' => $this->_paymentProcessorID,
      'credit_card_number' => '4111111111111111',
      'cvv2' => '123',
      'credit_card_exp_date' => [
        'M' => '9',
        'Y' => date('Y') + 1,
      ],
      'credit_card_type' => 'Visa',
      'billing_first_name' => 'Test',
      'billing_middlename' => 'Last',
      'billing_street_address-5' => '10 Test St',
      'billing_city-5' => 'Test',
      'billing_state_province_id-5' => '1003',
      'billing_postal_code-5' => '90210',
      'billing_country_id-5' => '1228',
    ];
  }

  /**
   * Test renewing an expired membership.
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function testSubmitRenewExpired() {
    $form = $this->getForm(NULL);
    $this->createLoggedInUser();
    $originalMembership = $this->callAPISuccessGetSingle('membership', []);
    $this->callAPISuccess('Membership', 'create', [
      'status_id' => 'Expired',
      'id' => $originalMembership['id'],
      'start_date' => '2019-03-01',
      'join_date' => '2019-03-01',
      'end_date' => '2020-03-24',
      'source' => 'sauce',
    ]);

    $params = [
      'contact_id' => $this->_individualId,
      'membership_type_id' => [$this->ids['contact']['organization'], $this->membershipTypeAnnualFixedID],
      'renewal_date' => '2020-06-10',
      'financial_type_id' => '2',
      'num_terms' => '1',
      'from_email_address' => '"Demonstrators Anonymous" <info@example.org>',
      'record_contribution' => '1',
      'total_amount' => '100.00',
      'receive_date' => '2020-06-05 06:05:00',
      'payment_instrument_id' => '4',
      'contribution_status_id' => '1',
      'send_receipt' => '1',
    ];
    $form->testSubmit($params);
    $renewedMembership = $this->callAPISuccessGetSingle('Membership', ['id' => $originalMembership['id']]);
    $this->assertEquals('sauce', $renewedMembership['source']);
    $this->assertEquals(date('Y-01-01'), $renewedMembership['start_date']);
    $this->assertEquals(date('2019-03-01'), $renewedMembership['join_date']);
    $this->assertEquals(date('Y-12-31'), $renewedMembership['end_date']);
    $log = $this->callAPISuccessGetSingle('MembershipLog', ['membership_id' => $renewedMembership['id'], 'options' => ['limit' => 1, 'sort' => 'id DESC']]);
    $this->assertEquals(date('Y-01-01'), $log['start_date']);
    $this->assertEquals(date('Y-12-31'), $log['end_date']);
    $this->assertEquals(date('Y-m-d'), $log['modified_date']);
    $this->assertEquals(CRM_Core_PseudoConstant::getKey('CRM_Member_BAO_Membership', 'status_id', 'Current'), $log['status_id']);
  }

}

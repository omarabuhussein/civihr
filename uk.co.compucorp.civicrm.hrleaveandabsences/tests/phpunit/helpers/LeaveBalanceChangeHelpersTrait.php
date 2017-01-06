<?php

use CRM_HRLeaveAndAbsences_BAO_LeaveBalanceChange as LeaveBalanceChange;
use CRM_HRLeaveAndAbsences_BAO_LeaveRequestDate as LeaveRequestDate;
use CRM_HRLeaveAndAbsences_BAO_LeaveRequest as LeaveRequest;
use CRM_HRLeaveAndAbsences_Test_Fabricator_LeaveBalanceChange as LeaveBalanceChangeFabricator;
use CRM_HRLeaveAndAbsences_Test_Fabricator_TOILRequest as TOILRequestFabricator;

trait CRM_HRLeaveAndAbsences_LeaveBalanceChangeHelpersTrait {

  private $balanceChangeTypes = [];

  public function getBalanceChangeTypeValue($type) {
    if(empty($this->balanceChangeTypes)) {
      $this->balanceChangeTypes = array_flip(LeaveBalanceChange::buildOptions('type_id'));
    }

    return $this->balanceChangeTypes[$type];
  }

  public function createEntitlementBalanceChange($entitlementID, $amount, $type, $expiryDate = null) {
    $params = [
      'type_id' => $type,
      'source_id' => $entitlementID,
      'source_type' => 'entitlement',
      'amount' => $amount
    ];

    if($expiryDate) {
      $params['expiry_date'] = $expiryDate;
    }

    return LeaveBalanceChange::create($params);
  }

  public function createLeaveBalanceChange($entitlementID, $amount) {
    return $this->createEntitlementBalanceChange(
      $entitlementID,
      $amount,
      $this->getBalanceChangeTypeValue('Leave')
    );
  }

  public function createOverriddenBalanceChange($entitlementID, $amount) {
    return $this->createEntitlementBalanceChange(
      $entitlementID,
      $amount,
      $this->getBalanceChangeTypeValue('Overridden')
    );
  }

  public function createBroughtForwardBalanceChange($entitlementID, $amount, $expiryDate = null) {
    return $this->createEntitlementBalanceChange(
      $entitlementID,
      $amount,
      $this->getBalanceChangeTypeValue('Brought Forward'),
      $expiryDate
    );
  }

  public function createPublicHolidayBalanceChange($entitlementID, $amount) {
    return $this->createEntitlementBalanceChange(
      $entitlementID,
      $amount,
      $this->getBalanceChangeTypeValue('Public Holiday')
    );
  }

  public function createExpiredBroughtForwardBalanceChange($entitlementID, $amount, $expiredAmount, $expiredByNoOfDays = null) {
    $this->createEntitlementBalanceChange(
      $entitlementID,
      $amount,
      $this->getBalanceChangeTypeValue('Brought Forward')
    );

    $broughtForwardBalanceChangeID = $this->getLastIdInTable(LeaveBalanceChange::getTableName());
    if(!$expiredByNoOfDays) {
      $expiredByNoOfDays = 2;
    }
    LeaveBalanceChange::create([
      'type_id' => $this->getBalanceChangeTypeValue('Brought Forward'),
      'source_id' => $entitlementID,
      'source_type' => 'entitlement',
      'amount' => $expiredAmount * -1, //expired amounts should be negative
      'expired_balance_change_id' => $broughtForwardBalanceChangeID,
      'expiry_date' => date('YmdHis', strtotime("-{$expiredByNoOfDays} day"))
    ]);
  }

  public function createExpiredTOILRequestBalanceChange($typeID, $contactID, $status, $fromDate, $toDate, $toilToAccrue, $expiryDate, $expiredAmount) {
    $toilRequest = TOILRequestFabricator::fabricateWithoutValidation([
      'type_id' => $typeID,
      'contact_id' => $contactID,
      'status_id' => $status,
      'from_date' => $fromDate,
      'to_date' => $toDate,
      'toil_to_accrue' => $toilToAccrue,
      'duration' => 200,
      'expiry_date' => $expiryDate
    ]);

    $toilBalanceChange = $this->findToilRequestBalanceChange($toilRequest->id);
    return LeaveBalanceChangeFabricator::fabricate([
      'source_id' => $toilBalanceChange->source_id,
      'source_type' => $toilBalanceChange->source_type,
      'amount' => $expiredAmount * -1,
      'expiry_date' => CRM_Utils_Date::processDate($toilBalanceChange->expiry_date),
      'expired_balance_change_id' => $toilBalanceChange->id,
      'type_id' => $this->getBalanceChangeTypeValue('Debit')
    ]);
  }

  /**
   * This is a helper method to create the fixtures a Leave Request and its
   * respective dates and balance changes.
   *
   * Creating a real leave request will involve a lot more complicate steps, like
   * calculating the amount of days to be deducted based on a work pattern, but
   * we don't need all that for the tests. Only having the data on the right
   * tables is enough, and that's what this method does.
   *
   * @param $typeID
   *    The ID of the Absence Type this Leave Request will belong to
   * @param $contactID
   *    The ID of the Contact (user) this Leave Request will belong to
   * @param int $status
   *    One of the values of the Leave Request Status option list
   * @param $fromDate
   *    The start date of the leave request
   * @param null $toDate
   *    The end date of the leave request. If null, it means it starts and ends at the same date
   *
   * @internal param int $entitlementID The ID of the entitlement to which the leave request will be added to*    The ID of the entitlement to which the leave request will be added to
   */
  public function createLeaveRequestBalanceChange($typeID, $contactID, $status, $fromDate, $toDate = null) {
    $leaveRequestTable = LeaveRequest::getTableName();
    $startDate = new DateTime($fromDate);

    if (!$toDate) {
      $endDate = new DateTime($fromDate);
    }
    else {
      $endDate = new DateTime($toDate);
    }

    $fromDate = "'{$fromDate}'";
    $toDate = $toDate ? "'{$toDate}'" : $fromDate;
    $leaveRequestDateTypes = array_flip(LeaveRequest::buildOptions('from_date_type', 'validate'));
    $dateType = $leaveRequestDateTypes['all_day'];

    $query = "
      INSERT INTO {$leaveRequestTable}(type_id, contact_id, status_id, from_date, to_date, from_date_type, to_date_type)
      VALUES({$typeID}, {$contactID}, {$status}, {$fromDate}, {$toDate}, {$dateType}, {$dateType} )
    ";

    CRM_Core_DAO::executeQuery($query);
    $leaveRequestID = $this->getLastIdInTable($leaveRequestTable);

    // We need to add 1 day to the end date to include it
    // when we loop through the DatePeriod
    $endDate->modify('+1 day');
    $interval   = new DateInterval('P1D');
    $datePeriod = new DatePeriod($startDate, $interval, $endDate);

    $leaveRequestDateTableName = LeaveRequestDate::getTableName();
    $balanceChangeTableName = LeaveBalanceChange::getTableName();

    $debitBalanceChangeType = $this->getBalanceChangeTypeValue('Debit');

    foreach ($datePeriod as $date) {
      $dbDate = $date->format('Y-m-d');

      CRM_Core_DAO::executeQuery("
        INSERT INTO {$leaveRequestDateTableName}(date, leave_request_id)
        VALUES('{$dbDate}', {$leaveRequestID})
      ");

      $dateId = $this->getLastIdInTable($leaveRequestDateTableName);

      CRM_Core_DAO::executeQuery("
        INSERT INTO {$balanceChangeTableName}(type_id, amount, source_id, source_type)
        VALUES({$debitBalanceChangeType}, -1, {$dateId}, '" . LeaveBalanceChange::SOURCE_LEAVE_REQUEST_DAY . "')
      ");
    }

  }

  public function getLastIdInTable($tableName) {
    $dao = CRM_Core_DAO::executeQuery("SELECT id FROM {$tableName} ORDER BY id DESC LIMIT 1");
    $dao->fetch();
    return (int)$dao->id;
  }

  /**
   * Finds a LeaveBalanceChange associated with the TOILRequest with the given ID
   *
   * @param int $toilRequestID
   *
   * @return \CRM_HRLeaveAndAbsences_BAO_LeaveBalanceChange|null
   */
  public function findToilRequestBalanceChange($toilRequestID) {
    $balanceChange = new LeaveBalanceChange();
    $balanceChange->source_id = $toilRequestID;
    $balanceChange->source_type = LeaveBalanceChange::SOURCE_TOIL_REQUEST;

    if($balanceChange->find()) {
      $balanceChange->fetch();

      return $balanceChange;
    }

    return null;
  }
}

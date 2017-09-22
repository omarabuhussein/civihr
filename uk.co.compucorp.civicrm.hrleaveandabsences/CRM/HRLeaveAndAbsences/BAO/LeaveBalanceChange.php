<?php

use CRM_HRLeaveAndAbsences_BAO_AbsencePeriod as AbsencePeriod;
use CRM_HRLeaveAndAbsences_BAO_LeavePeriodEntitlement as LeavePeriodEntitlement;
use CRM_HRLeaveAndAbsences_BAO_LeaveRequestDate as LeaveRequestDate;
use CRM_HRLeaveAndAbsences_BAO_LeaveRequest as LeaveRequest;
use CRM_HRLeaveAndAbsences_BAO_ContactWorkPattern as ContactWorkPattern;
use CRM_Hrjobcontract_BAO_HRJobContract as HRJobContract;
use CRM_Hrjobcontract_BAO_HRJobContractRevision as HRJobContractRevision;
use CRM_Hrjobcontract_BAO_HRJobDetails as HRJobDetails;

class CRM_HRLeaveAndAbsences_BAO_LeaveBalanceChange extends CRM_HRLeaveAndAbsences_DAO_LeaveBalanceChange {

  const SOURCE_ENTITLEMENT = 'entitlement';
  const SOURCE_LEAVE_REQUEST_DAY = 'leave_request_day';

  /**
   * Create a new LeaveBalanceChange based on array-data
   *
   * @param array $params key-value pairs
   *
   * @return CRM_HRLeaveAndAbsences_BAO_LeaveBalanceChange|NULL
   */
  public static function create($params) {
    $entityName = 'LeaveBalanceChange';
    $hook = empty($params['id']) ? 'create' : 'edit';

    CRM_Utils_Hook::pre($hook, $entityName, CRM_Utils_Array::value('id', $params), $params);
    $instance = new self();
    $instance->copyValues($params);
    $instance->save();
    CRM_Utils_Hook::post($hook, $entityName, $instance->id, $instance);

    return $instance;
  }

  /**
   * Returns the sum of all balance changes between the given LeavePeriodEntitlement
   * dates.
   *
   * This method can also sum only balance changes caused by leave requests with
   * specific statuses. For this, one can pass an array of statuses as the
   * $leaveRequestStatus parameter.
   *
   * Note 1: the balance changes linked to the given LeavePeriodEntitlement, that
   * is source_id == entitlement->id and source_type == 'entitlement', will also
   * be included in the sum.
   *
   * Note 2: for parity with the LeaveRequest.get and LeaveRequest.getFull APIs,
   * this method will only consider balance changes from leave requests that
   * overlap contracts
   *
   * @param \CRM_HRLeaveAndAbsences_BAO_LeavePeriodEntitlement $periodEntitlement
   *   The LeavePeriodEntitlement to get the Balance to
   * @param array $leaveRequestStatus
   *   An array of values from Leave Request Status option list
   * @param bool $expiredOnly
   *   When this param is set to true, the method will consider only the expired
   *   Balance Changes. Otherwise, it will consider all the Balance Changes,
   *   including the expired ones.
   * @param array $excludeLeaveIds
   *   An array of Leave request ID's to be excluded from
   *   the entitlement balance calculation.
   *
   * @return float
   */
  public static function getBalanceForEntitlement(LeavePeriodEntitlement $periodEntitlement, $leaveRequestStatus = [], $expiredOnly = false, $excludeLeaveIds = []) {
    $balanceChangeTable = self::getTableName();
    $leaveRequestDateTable = LeaveRequestDate::getTableName();
    $leaveRequestTable = LeaveRequest::getTableName();
    $contractTable = HRJobContract::getTableName();
    $contractRevisionTable = HRJobContractRevision::getTableName();
    $contractDetailsTable = HRJobDetails::getTableName();

    $whereLeaveRequestDates = self::buildLeaveRequestDateWhereClause($periodEntitlement);

    $whereLeaveRequestStatus = '';
    if(is_array($leaveRequestStatus) && !empty($leaveRequestStatus)) {
      array_walk($leaveRequestStatus, 'intval');
      $whereLeaveRequestStatus = ' AND leave_request.status_id IN('. implode(', ', $leaveRequestStatus) .')';
    }

    $whereExcludeLeaveRequestIds = '';
    if(is_array($excludeLeaveIds) && !empty($excludeLeaveIds)) {
      $whereExcludeLeaveRequestIds = ' AND leave_request.id NOT IN('. implode(', ', $excludeLeaveIds) .')';
    }

    $query = "
      SELECT SUM(leave_balance_change.amount) balance
      FROM {$balanceChangeTable} leave_balance_change
      LEFT JOIN {$leaveRequestDateTable} leave_request_date
             ON leave_balance_change.source_id = leave_request_date.id AND
                leave_balance_change.source_type = '". self::SOURCE_LEAVE_REQUEST_DAY ."'
      LEFT JOIN {$leaveRequestTable} leave_request
             ON leave_request_date.leave_request_id = leave_request.id AND
                leave_request.is_deleted = 0
      LEFT JOIN {$contractTable} contract
             ON leave_request.contact_id = contract.contact_id
      LEFT JOIN {$contractRevisionTable} contract_revision
            ON contract_revision.id = (
              SELECT id FROM {$contractRevisionTable} contract_revision2
              WHERE contract_revision2.jobcontract_id = contract.id
              ORDER BY contract_revision2.effective_date DESC
              LIMIT 1
            )
      LEFT JOIN {$contractDetailsTable} contract_details
             ON contract_revision.details_revision_id = contract_details.jobcontract_revision_id

      WHERE ((
              $whereLeaveRequestDates AND
              contract.deleted = 0 AND
              (
                leave_request.from_date <= contract_details.period_end_date OR
                contract_details.period_end_date IS NULL
              )  AND
              (
                leave_request.to_date >= contract_details.period_start_date OR
                (leave_request.to_date IS NULL AND leave_request.from_date >= contract_details.period_start_date)
              )  AND
              leave_request.type_id = {$periodEntitlement->type_id} AND
              leave_request.contact_id = {$periodEntitlement->contact_id}
              $whereLeaveRequestStatus
              $whereExcludeLeaveRequestIds
            )
            OR
            (
              leave_balance_change.source_id = {$periodEntitlement->id} AND
              leave_balance_change.source_type = '" . self::SOURCE_ENTITLEMENT . "'
            ))
    ";

    if($expiredOnly) {
      $query .= ' AND leave_balance_change.expired_balance_change_id IS NOT NULL';
    }

    $result = CRM_Core_DAO::executeQuery($query);
    $result->fetch();

    return (float)$result->balance;
  }

  /**
   * Returns the LeaveBalanceChange instances that are part of the
   * LeavePeriodEntitlement with the given ID.
   *
   * The Breakdown is made of the balance changes representing the parts that,
   * together, make the period entitlement. They are: The Leave, the Brought
   * Forward and the Public Holidays. These are all balance changes, where the
   * source_id is the LeavePeriodEntitlement's ID and source_type is equal to
   * "entitlement", since they're are created during the entitlement calculation.
   * Passing true for $returnExpiredOnly parameter will return only expired leave balance changes
   * while Passing false will return only Non expired leave balance changes for the entitlement ID
   *
   * @param int $entitlementID
   *   The ID of the LeavePeriodEntitlement to get the Breakdown to
   * @param boolean $returnExpiredOnly
   *   Whether to return Only Expired or Only Non Expired LeaveBalanceChanges
   *
   * @return CRM_HRLeaveAndAbsences_BAO_LeaveBalanceChange[]
   */
  public static function getBreakdownBalanceChangesForEntitlement($entitlementID, $returnExpiredOnly = false) {
    $entitlementID = (int)$entitlementID;
    $balanceChangeTable = self::getTableName();

    if(!$returnExpiredOnly){
      $expiredBalanceWhereCondition = " AND expired_balance_change_id IS NULL";
    }
    if($returnExpiredOnly){
      $expiredBalanceWhereCondition = " AND (expired_balance_change_id IS NOT NULL AND expiry_date < %1)";
    }

    $query = "
      SELECT *
      FROM {$balanceChangeTable}
      WHERE source_id = {$entitlementID} AND
            source_type = '" . self::SOURCE_ENTITLEMENT . "' {$expiredBalanceWhereCondition}
      ORDER BY id
    ";

    $changes = [];
    $params = [
      1 => [date('Y-m-d'), 'String']
    ];

    $result = CRM_Core_DAO::executeQuery($query, $params, true, self::class);
    while($result->fetch()) {
      $changes[] = clone $result;
    }

    return $changes;
  }

  /**
   * Returns the balance for the Balance Changes that are part of the
   * LeavePeriodEntitlement with the given ID.
   *
   * This basically gets the output of getBreakdownBalanceChangesForEntitlement()
   * and sums up the amount of the returned LeaveBalanceChange instances.
   *
   * @see CRM_HRLeaveAndAbsences_BAO_LeaveBalanceChange::getBreakdownBalanceChangesForEntitlement()
   *
   * @param int $entitlementID
   *    The ID of the LeavePeriodEntitlement to get the Breakdown Balance to
   *
   * @return float
   */
  public static function getBreakdownBalanceForEntitlement($entitlementID) {
    $balanceChanges = self::getBreakdownBalanceChangesForEntitlement($entitlementID);

    $balance = 0.0;
    foreach($balanceChanges as $balanceChange) {
      $balance += (float)$balanceChange->amount;
    }

    return $balance;
  }

  /**
   * Returns the sum of all balance changes generated by LeaveRequests on
   * LeavePeriodEntitlement with the given ID.
   *
   * This method can also sum only balance changes caused by leave requests with
   * specific statuses. For this, one can pass an array of statuses as the
   * $leaveRequestStatus parameter.
   *
   * It's also possible to get the balance only for leave requests taken between
   * a given date range. For this, one can use the $dateLimit and $dateStart params.
   *
   * Public Holidays may also be stored as Leave Requests. If you want to exclude
   * them from the sum, or only sum their balance changes, you can use the
   * $excludePublicHolidays or $includePublicHolidaysOnly params.
   *
   * Since balance changes caused by LeaveRequests are negative, this method
   * will return a negative number.
   *
   * Note: for parity with the LeaveRequest.get and LeaveRequest.getFull APIs,
   * this method only sums the balance changes for Leave Requests overlapping
   * active contracts
   *
   * @param \CRM_HRLeaveAndAbsences_BAO_LeavePeriodEntitlement $periodEntitlement
   * @param array $leaveRequestStatus
   *   An array of values from Leave Request Status option list
   * @param \DateTime $dateLimit
   *   When given, will make the method count only days taken as leave up to this date
   * @param \DateTime $dateStart
   *   When given, will make the method count only days taken as leave starting from this date
   * @param bool $excludePublicHolidays
   *   When true, it won't sum the balance changes for Public Holiday Leave Requests
   * @param bool $includePublicHolidaysOnly
   *   When true, it won't sum only the balance changes for Public Holiday Leave Requests
   *
   * @return float
   */
  public static function getLeaveRequestBalanceForEntitlement(
    LeavePeriodEntitlement $periodEntitlement,
    $leaveRequestStatus = [],
    DateTime $dateLimit = NULL,
    DateTime $dateStart = NULL,
    $excludePublicHolidays = false,
    $includePublicHolidaysOnly = false
  ) {

    $balanceChangeTable = self::getTableName();
    $leaveRequestDateTable = LeaveRequestDate::getTableName();
    $leaveRequestTable = LeaveRequest::getTableName();
    $contractTable = HRJobContract::getTableName();
    $contractRevisionTable = HRJobContractRevision::getTableName();
    $contractDetailsTable = HRJobDetails::getTableName();

    $whereLeaveRequestDates = self::buildLeaveRequestDateWhereClause($periodEntitlement);

    $query = "
      SELECT SUM(leave_balance_change.amount) balance
      FROM {$balanceChangeTable} leave_balance_change
      INNER JOIN {$leaveRequestDateTable} leave_request_date
              ON leave_balance_change.source_id = leave_request_date.id AND
                 leave_balance_change.source_type = '" . self::SOURCE_LEAVE_REQUEST_DAY . "'
      INNER JOIN {$leaveRequestTable} leave_request
              ON leave_request_date.leave_request_id = leave_request.id AND
                 leave_request.is_deleted = 0
      INNER JOIN {$contractTable} contract
             ON leave_request.contact_id = contract.contact_id
      INNER JOIN {$contractRevisionTable} contract_revision
            ON contract_revision.id = (
              SELECT id FROM {$contractRevisionTable} contract_revision2
              WHERE contract_revision2.jobcontract_id = contract.id
              ORDER BY contract_revision2.effective_date DESC
              LIMIT 1
            )
      INNER JOIN {$contractDetailsTable} contract_details
             ON contract_revision.details_revision_id = contract_details.jobcontract_revision_id

      WHERE {$whereLeaveRequestDates} AND
            contract.deleted = 0 AND
            (
              leave_request.from_date <= contract_details.period_end_date OR
              contract_details.period_end_date IS NULL
            )  AND
            (
              leave_request.to_date >= contract_details.period_start_date OR
              (leave_request.to_date IS NULL AND leave_request.from_date >= contract_details.period_start_date)
            ) AND
            leave_balance_change.expired_balance_change_id IS NULL AND
            leave_request.type_id = {$periodEntitlement->type_id} AND
            leave_request.contact_id = {$periodEntitlement->contact_id}
    ";

    if(is_array($leaveRequestStatus) && !empty($leaveRequestStatus)) {
      array_walk($leaveRequestStatus, 'intval');
      $query .= ' AND leave_request.status_id IN('. implode(', ', $leaveRequestStatus) .')';
    }

    if($dateLimit) {
      $query .= " AND leave_request_date.date <= '{$dateLimit->format('Y-m-d')}'";
    }

    if($dateStart) {
      $query .= " AND leave_request_date.date >= '{$dateStart->format('Y-m-d')}'";
    }

    if($excludePublicHolidays) {
      $query .= " AND leave_request.request_type != '" . LeaveRequest::REQUEST_TYPE_PUBLIC_HOLIDAY . "'";
    }

    if($includePublicHolidaysOnly) {
      $query .= " AND leave_request.request_type = '" . LeaveRequest::REQUEST_TYPE_PUBLIC_HOLIDAY . "'";
    }

    $result = CRM_Core_DAO::executeQuery($query);
    $result->fetch();

    return (float)$result->balance;
  }

  /**
   * Returns all the LeaveBalanceChanges linked to the LeaveRequestDates of the
   * given LeaveRequest
   *
   * @param \CRM_HRLeaveAndAbsences_BAO_LeaveRequest $leaveRequest
   *
   * @return CRM_HRLeaveAndAbsences_BAO_LeaveBalanceChange[]
   */
  public static function getBreakdownForLeaveRequest(LeaveRequest $leaveRequest) {
    $balanceChangeTable = self::getTableName();
    $leaveRequestDateTable = LeaveRequestDate::getTableName();
    $leaveRequestTable = LeaveRequest::getTableName();

    $query = "
      SELECT bc.*
      FROM {$balanceChangeTable} bc
      INNER JOIN {$leaveRequestDateTable} lrd
        ON bc.source_id = lrd.id AND bc.source_type = %1
      INNER JOIN {$leaveRequestTable} lr
        ON lrd.leave_request_id = lr.id
      WHERE lr.id = %2
      AND lr.is_deleted = 0
      ORDER BY id
    ";

    $params = [
      1 => [self::SOURCE_LEAVE_REQUEST_DAY, 'String'],
      2 => [$leaveRequest->id, 'Integer'],
    ];

    $changes = [];

    $result = CRM_Core_DAO::executeQuery($query, $params, true, self::class);
    while($result->fetch()) {
      $changes[] = clone $result;
    }

    return $changes;
  }

  /**
   * Returns the sum of all LeaveBalanceChanges linked to the LeaveRequestDates
   * of the given LeaveRequest.
   *
   * This method also accounts for expired/non-expired balance changes. By
   * default, it will only consider the non expired balance changes. So, for
   * example, if you have a LeaveRequest of type TOIL, with 5 days accrued and
   * 3 expired, it will return 5 as the balance change (the original amount,
   * ignoring the expired days). Now, if you use $expiredOnly = true for the
   * same leave request, the method will return -3 (the expired amount).
   *
   * @see \CRM_HRLeaveAndAbsences_BAO_LeaveBalanceChange::getBreakdownForLeaveRequest()
   *
   * @param \CRM_HRLeaveAndAbsences_BAO_LeaveRequest $leaveRequest
   *
   * @param bool $expiredOnly
   *  If true, then the method will only account for expiry balance changed.
   *  Otherwise, only for non expired ones. The default value is false.
   *
   * @return float
   */
  public static function getTotalBalanceChangeForLeaveRequest(LeaveRequest $leaveRequest, $expiredOnly = false) {
    $balanceChanges = self::getBreakdownForLeaveRequest($leaveRequest);

    $balance = 0.0;
    foreach($balanceChanges as $balanceChange) {
      if((!$expiredOnly && $balanceChange->expired_balance_change_id === null) ||
         ($expiredOnly && $balanceChange->expired_balance_change_id !== null)
      ) {
        $balance += (float)$balanceChange->amount;
      }
    }

    return $balance;
  }

  /**
   * Recalculate remaining amount for each of the about to be expired LeaveBalanceChanges (i.e expiry date < today)
   * against the leave dates taken within the LeaveBalanceChange start and expiry date for same contact and absence type
   * and deducts the appropriate amount from the remaining amount before creating expiry records for them.
   *
   * @return int The number of records created
   */
  public static function createExpiryRecords() {
    $numberOfRecordsCreated = 0;

    $balanceChangesToExpire = self::getBalanceChangesToExpire();

    if(empty($balanceChangesToExpire)) {
      return $numberOfRecordsCreated;
    }

    $datesOverlappingBalanceChangesToExpire = self::getDatesOverlappingBalanceChangesToExpire($balanceChangesToExpire);

    return self::calculateExpiryAmount($balanceChangesToExpire, $datesOverlappingBalanceChangesToExpire);
  }

  /**
   * Returns the LeavePeriodEntitlement of this LeaveBalanceChange.
   *
   * If the source type is entitlement, then we return the LeavePeriodEntitlement
   * with the same id as the source_id. If it's leave_request_day, then we return
   * the LeavePeriodEntitlement associated with the LeaveRequestDate associated
   * LeaveRequest.
   *
   * @return \CRM_HRLeaveAndAbsences_BAO_LeavePeriodEntitlement
   *
   * @throws \RuntimeException
   */
  public function getLeavePeriodEntitlement() {
    switch ($this->source_type) {
      case self::SOURCE_ENTITLEMENT:
        return LeavePeriodEntitlement::findById($this->source_id);

      case self::SOURCE_LEAVE_REQUEST_DAY:
        $leaveRequest = $this->getLeaveRequestDateLeaveRequest($this->source_id);
        return LeavePeriodEntitlement::getForLeaveRequest($leaveRequest);

      default:
        throw new RuntimeException("'{$this->source_type}' is not a valid Balance Change source type");
    }
  }

  /**
   * Returns the LeaveRequest associated with LeaveRequestDate of the given ID
   *
   * @param int $leaveRequestDateID
   *
   * @return \CRM_HRLeaveAndAbsences_BAO_LeaveRequest
   */
  private function getLeaveRequestDateLeaveRequest($leaveRequestDateID) {
    $leaveRequestDate = LeaveRequestDate::findById($leaveRequestDateID);
    return LeaveRequest::findById($leaveRequestDate->leave_request_id);
  }

  /**
   * Creates the where clause to filter leave requests by the LeavePeriodEntitlement
   * dates.
   *
   * @param \CRM_HRLeaveAndAbsences_BAO_LeavePeriodEntitlement $periodEntitlement
   *
   * @return string
   */
  private static function buildLeaveRequestDateWhereClause(
    LeavePeriodEntitlement $periodEntitlement
  ) {
    $contractsDates = $periodEntitlement->getStartAndEndDates();

    $leaveRequestDatesClauses = [];
    foreach ($contractsDates as $dates) {
      $leaveRequestDatesClauses[] = "leave_request_date.date BETWEEN '{$dates['start_date']}' AND '{$dates['end_date']}'";
    }
    $whereLeaveRequestDates = implode(' OR ', $leaveRequestDatesClauses);

    // This is just a trick to make it easier to
    // interpolate this clause in SQL query string.
    // if there's no date, we return the clause as a catch all condition
    if(empty($whereLeaveRequestDates)) {
      $whereLeaveRequestDates = '1=1';
    }

    // Finally, since this is a list of conditions separate
    // by OR, we wrap it in parenthesis
    return "($whereLeaveRequestDates)";
  }

  /**
   * Calculates the amount to be deducted for a leave taken by the given contact
   * on the given date.
   *
   * This works by fetching the contact's work pattern active during the given
   * date and then using it to get the amount of days to be deducted. If there's
   * no work pattern assigned to the contact, the default work pattern will be
   * used instead.
   *
   * This method also considers the existence of Public Holidays Leave Requests
   * overlapping the dates of the LeaveRequest. For those dates, the amount of
   * days to be deducted will be 0.
   *
   * @param \CRM_HRLeaveAndAbsences_BAO_LeaveRequest $leaveRequest
   *  The LeaveRequest which the $date belongs to
   * @param \DateTime $date
   *
   * @return float
   */
  public static function calculateAmountForDate(LeaveRequest $leaveRequest, DateTime $date) {
    if(self::thereIsAPublicHolidayLeaveRequest($leaveRequest, $date)) {
      return 0.0;
    }

    $workPattern = ContactWorkPattern::getWorkPattern($leaveRequest->contact_id, $date);
    $startDate = ContactWorkPattern::getStartDate($leaveRequest->contact_id, $date);

    if(!$workPattern || !$startDate) {
      return 0.0;
    }

    return $workPattern->getLeaveDaysForDate($date, $startDate) * -1;
  }

  /**
   * Returns if there is a Public Holiday Leave Request for the given
   * $date and with the same contact_id and type_id as the given $leaveRequest
   *
   * @param \CRM_HRLeaveAndAbsences_BAO_LeaveRequest $leaveRequest
   * @param \DateTime $date
   *
   * @return bool
   */
  private static function thereIsAPublicHolidayLeaveRequest(LeaveRequest $leaveRequest, DateTime $date) {
    $balanceChange = self::getExistingBalanceChangeForALeaveRequestDate($leaveRequest, $date);

    if(is_null($balanceChange)) {
      return false;
    }

    $balanceChangeTypes = array_flip(self::buildOptions('type_id'));

    return $balanceChange->type_id == $balanceChangeTypes['Public Holiday'];
  }

  /**
   * Returns an existing LeaveBalanceChange record linked to a LeaveRequestDate
   * with the same date as $date and belonging to a LeaveRequest with the same
   * contact_id and type_id as those of the given $leaveRequest.
   *
   * @param \CRM_HRLeaveAndAbsences_BAO_LeaveRequest $leaveRequest
   * @param \DateTime $date
   *
   * @return \CRM_Core_DAO|null|object
   */
  public static function getExistingBalanceChangeForALeaveRequestDate(LeaveRequest $leaveRequest, DateTime $date) {
    $balanceChangeTable = self::getTableName();
    $leaveRequestDateTable = LeaveRequestDate::getTableName();
    $leaveRequestTable = LeaveRequest::getTableName();

    $query = "
      SELECT bc.*
      FROM {$balanceChangeTable} bc
      INNER JOIN {$leaveRequestDateTable} lrd
        ON bc.source_id = lrd.id AND bc.source_type = %1
      INNER JOIN {$leaveRequestTable} lr
        ON lrd.leave_request_id = lr.id AND lr.is_deleted = 0
      WHERE lrd.date = %2 AND
            lr.contact_id = %3
      ORDER BY id
    ";

    $params = [
      1 => [self::SOURCE_LEAVE_REQUEST_DAY, 'String'],
      2 => [$date->format('Y-m-d'), 'String'],
      3 => [$leaveRequest->contact_id, 'Integer'],
    ];

    $result = CRM_Core_DAO::executeQuery($query, $params, true, self::class);

    if($result->N == 1) {
      $result->fetch();
      return $result;
    }

    return null;
  }

  /**
   * Deletes the LeaveBalanceChange linked to the given LeaveRequestDate
   *
   * @param \CRM_HRLeaveAndAbsences_BAO_LeaveRequestDate $date
   */
  public static function deleteForLeaveRequestDate(LeaveRequestDate $date) {
    $leaveBalanceChangeTable = self::getTableName();
    $query = "DELETE FROM {$leaveBalanceChangeTable} WHERE source_id = %1 AND source_type = %2";

    $params = [
      1 => [$date->id, 'Integer'],
      2 => [self::SOURCE_LEAVE_REQUEST_DAY, 'String']
    ];

    CRM_Core_DAO::executeQuery($query, $params);
  }

  /**
   * Deletes the LeaveBalanceChanges for a LeavePeriodEntitlement
   *
   * @param \CRM_HRLeaveAndAbsences_BAO_LeavePeriodEntitlement $leavePeriodEntitlement
   */
  public static function deleteForLeavePeriodEntitlement(LeavePeriodEntitlement $leavePeriodEntitlement) {
    $leaveBalanceChangeTable = self::getTableName();
    $query = "DELETE FROM {$leaveBalanceChangeTable} WHERE source_id = %1 AND source_type = %2";

    $params = [
      1 => [$leavePeriodEntitlement->id, 'Integer'],
      2 => [self::SOURCE_ENTITLEMENT, 'String']
    ];

    CRM_Core_DAO::executeQuery($query, $params);
  }

  /**
   * Deletes the LeaveBalanceChanges linked to all of the LeaveRequestDates of
   * the given LeaveRequest
   *
   * @param \CRM_HRLeaveAndAbsences_BAO_LeaveRequest $leaveRequest
   */
  public static function deleteAllForLeaveRequest(LeaveRequest $leaveRequest) {
    $leaveBalanceChangeTable = self::getTableName();
    $leaveRequestDateTable = LeaveRequestDate::getTableName();

    $query = "DELETE bc FROM {$leaveBalanceChangeTable} bc
              INNER JOIN {$leaveRequestDateTable} lrd
                ON bc.source_id = lrd.id AND bc.source_type = %1
              WHERE lrd.leave_request_id = %2";

    $params = [
      1 => [self::SOURCE_LEAVE_REQUEST_DAY, 'String'],
      2 => [$leaveRequest->id, 'Integer']
    ];

    CRM_Core_DAO::executeQuery($query, $params);
  }

  /**
   * Returns a list of dates which overlap the start_date and expiry_date of
   * each of the balance changes passed on the $balanceChangesToExpire param.
   * Each date is also followed by the amount of days deducted for it.
   *
   * Basically, this method goes to the LeaveRequestDate table and join with
   * the LeaveBalanceChange table where LeaveRequestDate.date is between the
   * start_date and expiry_date of the balance changes to expire.
   *
   * Note: This method also considers the LeavePeriodEntitlement in which the
   * balance changes and dates are contained and it will only return dates
   * within valid contracts and absence periods for the LeavePeriodEntitlement.
   *
   * @param array $balanceChangesToExpire
   *
   * @return array
   */
  private static function getDatesOverlappingBalanceChangesToExpire($balanceChangesToExpire) {
    $leaveRequestStatuses = LeaveRequest::getStatuses();

    $leaveRequestTable = LeaveRequest::getTableName();
    $leaveRequestDateTable = LeaveRequestDate::getTableName();
    $leaveBalanceChangeTable = self::getTableName();
    $leaveDates = [];
    foreach($balanceChangesToExpire as $balanceChangeToExpire) {
      $balanceChange = new self();
      $balanceChange->source_id = $balanceChangeToExpire['source_id'];
      $balanceChange->source_type = $balanceChangeToExpire['source_type'];
      $periodEntitlement = $balanceChange->getLeavePeriodEntitlement();

      $wherePeriodEntitlementDates = [];
      $periodStartAndEndDates = $periodEntitlement->getStartAndEndDates();
      foreach($periodStartAndEndDates as $dates) {
        $wherePeriodEntitlementDates[] = "leave_request_date.date BETWEEN '{$dates['start_date']}' AND '{$dates['end_date']}'";
      }
      $wherePeriodEntitlementDates[] = '1=1';
      $wherePeriodEntitlementDates = implode(' OR ', $wherePeriodEntitlementDates);

      $query = "
        SELECT
          leave_request_date.id,
          leave_request_date.date,
          leave_request.type_id,
          balance_change.amount
        FROM {$leaveRequestDateTable} leave_request_date
        INNER JOIN {$leaveBalanceChangeTable} balance_change
            ON balance_change.source_id = leave_request_date.id AND balance_change.source_type = %1
        INNER JOIN {$leaveRequestTable} leave_request
            ON leave_request_date.leave_request_id = leave_request.id AND
               (leave_request.request_type IN(%2, %3, %9) AND leave_request.is_deleted = 0)
        WHERE ({$wherePeriodEntitlementDates}) AND
              (leave_request_date.date BETWEEN %4 AND %5) AND
              (leave_request.status_id IN (%6, %10)) AND
              (leave_request.contact_id = %7) AND
              (leave_request.type_id = %8)
      ";

      $params = [
        1 => [self::SOURCE_LEAVE_REQUEST_DAY, 'String'],
        2 => [LeaveRequest::REQUEST_TYPE_SICKNESS, 'String'],
        3 => [LeaveRequest::REQUEST_TYPE_LEAVE, 'String'],
        4 => [$balanceChangeToExpire['start_date']->format('Y-m-d'), 'String'],
        5 => [$balanceChangeToExpire['expiry_date']->format('Y-m-d'), 'String'],
        6 => [$leaveRequestStatuses['approved'], 'String'],
        7 => [$periodEntitlement->contact_id, 'Integer'],
        8 => [$periodEntitlement->type_id, 'Integer'],
        9 => [LeaveRequest::REQUEST_TYPE_PUBLIC_HOLIDAY, 'String'],
        10 => [$leaveRequestStatuses['admin_approved'], 'String']
      ];

      $result = CRM_Core_DAO::executeQuery($query, $params);

      while($result->fetch()) {
        $leaveDates[$result->id] = [
          'id' => $result->id,
          'date' => new DateTime($result->date),
          'amount' => (float)$result->amount,
          'contact_id' => $periodEntitlement->contact_id,
          'absence_type_id' => $result->type_id
        ];
      }
    }

    return $leaveDates;
  }

  /**
   * Returns all the Balance Changes where the expiry date is in the past and
   * that still don't have an associated expired balance change (a balance change
   * where expired_balance_change_id is not null).
   *
   * This method returns the Balance Changes as an array and not as instances
   * of the LeaveBalanceChange BAO. It also returns a start_date, which is the
   * date this balance change became valid. It's calculated on following this
   * logic:
   * - If the Balance Change is linked to a LeaveRequest, then it will be the
   * from_date of that request
   * - If the Balance Change is linked to a LeavePeriodEntitlement, the the
   * start date will be the start_date of the AbsencePeriod linked to that
   * LeavePeriodEntitlement.
   *
   * @return array
   */
  private static function getBalanceChangesToExpire() {
    $balanceChangeTable     = self::getTableName();
    $leaveRequestDateTable  = LeaveRequestDate::getTableName();
    $leaveRequestTable      = LeaveRequest::getTableName();
    $periodEntitlementTable = LeavePeriodEntitlement::getTableName();
    $absencePeriodTable     = AbsencePeriod::getTableName();

    $query = "
      SELECT
        balance_to_expire.*,
        coalesce(absence_period.start_date, leave_request.from_date) as start_date,
        coalesce(leave_request.contact_id, period_entitlement.contact_id) as contact_id,
        coalesce(leave_request.type_id, period_entitlement.type_id) as absence_type_id
      FROM {$balanceChangeTable} balance_to_expire
      LEFT JOIN {$balanceChangeTable} expired_balance_change
             ON balance_to_expire.id = expired_balance_change.expired_balance_change_id
      LEFT JOIN {$leaveRequestDateTable} leave_request_date
            ON balance_to_expire.source_type = %1 AND balance_to_expire.source_id = leave_request_date.id
      LEFT JOIN {$leaveRequestTable} leave_request
            ON leave_request_date.leave_request_id = leave_request.id AND leave_request.is_deleted = 0
      LEFT JOIN {$periodEntitlementTable} period_entitlement
            ON balance_to_expire.source_type = %2 AND balance_to_expire.source_id = period_entitlement.id
      LEFT JOIN {$absencePeriodTable} absence_period
            ON period_entitlement.period_id = absence_period.id
      WHERE balance_to_expire.expiry_date IS NOT NULL AND
            balance_to_expire.expiry_date < CURDATE() AND
            balance_to_expire.expired_balance_change_id IS NULL AND
            expired_balance_change.id IS NULL
      ORDER BY balance_to_expire.expiry_date ASC, balance_to_expire.id ASC
    ";

    $params = [
      1 => [self::SOURCE_LEAVE_REQUEST_DAY, 'String'],
      2 => [self::SOURCE_ENTITLEMENT, 'String'],
    ];

    $result = CRM_Core_DAO::executeQuery($query, $params);
    $balanceChangesToExpire = [];
    while ($result->fetch()) {
      $balanceChangesToExpire[] = [
        'id'          => $result->id,
        'type_id'     => $result->type_id,
        'amount'      => (float) $result->amount,
        'start_date'  => new DateTime($result->start_date),
        'expiry_date' => new DateTime($result->expiry_date),
        'source_type' => $result->source_type,
        'source_id'   => $result->source_id,
        'contact_id'  => $result->contact_id,
        'absence_type_id' => $result->absence_type_id,
      ];
    }

    return $balanceChangesToExpire;
  }

  /**
   * This method returns the total sum of approved TOIL accrued for an absence type
   * by a contact over a given absence period
   *
   * @param int $contactID
   * @param int $typeID
   * @param \CRM_HRLeaveAndAbsences_BAO_AbsencePeriod $period
   *
   * @return float
   */
  public static function getTotalApprovedToilForPeriod(AbsencePeriod $period, $contactID, $typeID) {
    $leaveRequestStatusFilter = LeaveRequest::getApprovedStatuses();

    $totalApprovedTOIL = self::getTotalTOILBalanceChangeForContact(
      $contactID,
      $typeID,
      new DateTime($period->start_date),
      new DateTime($period->end_date),
      $leaveRequestStatusFilter
    );

    return $totalApprovedTOIL;
  }

  /**
   * This method calculates the sum of the Leave Requests of type toil (with the
   * given status) balance changes for a given contact over a given period of time.
   *
   * @param int $contactID
   * @param int $absenceTypeID
   * @param DateTime $startDate
   *   LeaveRequests with from_date >= this date will be included
   * @param DateTime $endDate
   *   LeaveRequests with with to_date <= this date will be included
   * @param array $statuses
   *   The statuses of the requests to be included in the calculation/summation.
   *   An array of values from Leave Request Status option list
   *
   * @return float
   */
  public static function getTotalTOILBalanceChangeForContact($contactID, $absenceTypeID, DateTime $startDate, DateTime $endDate, $statuses = []) {
    $leaveBalanceChangeTable = self::getTableName();
    $leaveRequestDateTable = LeaveRequestDate::getTableName();
    $leaveRequestTable = LeaveRequest::getTableName();

    $query = "SELECT SUM(bc.amount) balance
              FROM {$leaveBalanceChangeTable} bc
              INNER JOIN {$leaveRequestDateTable} lrd
                ON bc.source_id = lrd.id AND bc.source_type = %1
              INNER JOIN {$leaveRequestTable} lr
                ON lrd.leave_request_id = lr.id AND lr.is_deleted = 0
              WHERE
                lr.contact_id = %2 AND
                lr.from_date >= %3 AND
                lr.to_date <= %4 AND
                lr.type_id = %5 AND
                lr.request_type = %6";

    if (is_array($statuses) && !empty($statuses)) {
      array_walk($statuses, 'intval');
      $query .=' AND (lr.status_id IN('. implode(', ', $statuses) . '))';
    }

    $params = [
      1 => [self::SOURCE_LEAVE_REQUEST_DAY, 'String'],
      2 => [$contactID, 'Integer'],
      3 => [$startDate->format('Y-m-d'), 'String'],
      4 => [$endDate->format('Y-m-d'), 'String'],
      5 => [$absenceTypeID, 'Integer'],
      6 => [LeaveRequest::REQUEST_TYPE_TOIL, 'String']
    ];

    $result = CRM_Core_DAO::executeQuery($query, $params);
    $result->fetch();

    return (float)$result->balance;
  }

  /**
   * Recalculate the expired LeaveBalanceChanges for a contact
   * requesting LeaveRequest with past dates if the LeaveBalanceChanges
   * expired after the from_date of the LeaveRequest. Any affected
   * LeaveBalanceChange is updated after the recalculation.
   *
   * @param \CRM_HRLeaveAndAbsences_BAO_LeaveRequest $leaveRequest
   *
   * @return int
   *   The number of records updated
   */
  public static function recalculateExpiredBalanceChangesForLeaveRequestPastDates(LeaveRequest $leaveRequest) {
    $numberOfRecordsUpdated = 0;

    $expiredBalanceChanges = self::getExpiredBalanceChangesForLeaveRequestPeriod($leaveRequest);
    $leaveRequestPastDates = self::getDatesOverlappingBalanceChangesToExpire($expiredBalanceChanges);

    if(empty($expiredBalanceChanges) || empty($leaveRequestPastDates)) {
      return $numberOfRecordsUpdated;
    }

    return self::calculateExpiryAmount($expiredBalanceChanges, $leaveRequestPastDates);
  }

  /**
   * Get the expired LeaveBalanceChanges linked to the LeaveRequest Contact
   * whose expiry date was within the Absence Period linked to the given LeaveRequest
   * and also the expiry date is greater than the LeaveRequest from_date and the expired LeaveBalanceChange
   * absence type is same as that on the leave request.
   *
   * This is to ensure that only expired LeaveBalanceChanges that are eligible for recalculation
   * are fetched by the query. If the LeaveBalanceChange has expired before the LeaveRequest from_date,
   * then there is no need to fetch it.
   *
   * @param \CRM_HRLeaveAndAbsences_BAO_LeaveRequest $leaveRequest
   *
   * @return array
   */
  private static function getExpiredBalanceChangesForLeaveRequestPeriod(LeaveRequest $leaveRequest) {
    $balanceChangeTable = self::getTableName();
    $leaveRequestTable = LeaveRequest::getTableName();
    $leaveRequestDateTable = LeaveRequestDate::getTableName();
    $periodEntitlementTable = LeavePeriodEntitlement::getTableName();
    $absencePeriodTable = AbsencePeriod::getTableName();

    $leaveRequestFromDate = new DateTime($leaveRequest->from_date);
    $leaveRequestToDate =  new DateTime($leaveRequest->to_date);

    $absencePeriod = AbsencePeriod::getPeriodContainingDates(
      $leaveRequestFromDate,
      $leaveRequestToDate
    );

    $query = "
      SELECT
        bc.*,
        coalesce(absence_period.start_date, leave_request.from_date) as start_date
      FROM {$balanceChangeTable} bc
      LEFT JOIN {$leaveRequestDateTable} leave_request_date
            ON bc.source_type = %1 AND bc.source_id = leave_request_date.id
      LEFT JOIN {$leaveRequestTable} leave_request
            ON leave_request_date.leave_request_id = leave_request.id AND leave_request.is_deleted = 0
      LEFT JOIN {$periodEntitlementTable} period_entitlement
            ON bc.source_type = %2 AND bc.source_id = period_entitlement.id
      LEFT JOIN {$absencePeriodTable} absence_period
            ON period_entitlement.period_id = absence_period.id
      WHERE bc.expiry_date IS NOT NULL AND
            (bc.expiry_date BETWEEN %3 AND %4) AND
            bc.expiry_date >= %5 AND
            bc.expired_balance_change_id IS NULL AND
            (leave_request.contact_id = %6 OR period_entitlement.contact_id = %6) AND
            (leave_request.type_id = %7 OR period_entitlement.type_id = %7)
      ORDER BY bc.expiry_date ASC, bc.id ASC";

    $params = [
      1 => [self::SOURCE_LEAVE_REQUEST_DAY, 'String'],
      2 => [self::SOURCE_ENTITLEMENT, 'String'],
      3 => [$absencePeriod->start_date, 'String'],
      4 => [$absencePeriod->end_date, 'String'],
      5 => [$leaveRequestFromDate->format('Y-m-d'), 'String'],
      6 => [$leaveRequest->contact_id, 'Integer'],
      7 => [$leaveRequest->type_id, 'Integer']
    ];

    $result = CRM_Core_DAO::executeQuery($query, $params);

    $expiredBalanceChanges = [];
    while ($result->fetch()) {
      $expiredBalanceChanges[] = [
        'id' => $result->id,
        'type_id' => $result->type_id,
        'amount' => (float) $result->amount,
        'start_date' => new DateTime($result->start_date),
        'expiry_date' => new DateTime($result->expiry_date),
        'source_type' => $result->source_type,
        'source_id' => $result->source_id,
        'contact_id'  => $leaveRequest->contact_id,
        'absence_type_id' => $leaveRequest->type_id,
      ];
    }

    return $expiredBalanceChanges;
  }


  /**
   * This method checks each LeaveBalanceChanges against the leave dates
   * taken within the LeaveBalanceChange start and expiry date for same contact and absence type
   * and deducts the appropriate amount from the remaining amount.
   *
   * For expired LeaveBalanceChanges, the record
   * is simply updated while for  LeaveBalanceChanges just about to be expired,
   * an expiry record is created with the expired_balance_change_id pointing to the LeaveBalanceChange
   * to be expired.
   *
   * @param array $balanceChanges
   *   An array of  Leave Balance changes
   * @param array $datesOverlappingBalanceChanges
   *   An Array of Leave Request dates overlapping the Leave Balance changes
   *
   * @return int
   *   Number of records created/updated
   */
  private static function calculateExpiryAmount($balanceChanges, $datesOverlappingBalanceChanges) {
    $numberOfRecords = 0;

    foreach ($balanceChanges as $balanceChange) {
      $remainingAmount = abs($balanceChange['amount']);

      foreach ($datesOverlappingBalanceChanges as $i => $date) {
        $isSameContact = $balanceChange['contact_id'] == $date['contact_id'];
        $isSameAbsenceType = $balanceChange['absence_type_id'] == $date['absence_type_id'];
        $leaveDateWithinBalanceChangeDates = $date['date'] >= $balanceChange['start_date']
          && $date['date'] <= $balanceChange['expiry_date'];

        if ($isSameContact && $isSameAbsenceType && $leaveDateWithinBalanceChangeDates) {
          if ($remainingAmount >= abs($date['amount'])) {
            // Date already deducted, so now we remove it from the
            // array so it won't be deducted again from another
            // balance change
            unset($datesOverlappingBalanceChanges[$i]);
            $remainingAmount += $date['amount'];
          }

          if ($remainingAmount === 0) {
            break;
          }
        }
      }

      $params = [
        'source_id' => $balanceChange['source_id'],
        'source_type' => $balanceChange['source_type'],
        'type_id' => $balanceChange['type_id'],
        // Since these days should be deducted from the entitlement,
        // We need to store the expired amount as a negative number
        'amount' => $remainingAmount * -1,
        'expiry_date' => $balanceChange['expiry_date']->format('YmdHis'),
        'expired_balance_change_id' => $balanceChange['id']
      ];

      $expiryBalanceChange = new self();
      $expiryBalanceChange->expired_balance_change_id = $balanceChange['id'];
      $expiryBalanceChange->find(true);

      if($expiryBalanceChange->id) {
        $params['id'] = $expiryBalanceChange->id;
      }

      self::create($params);

      $numberOfRecords++;
    }

    return $numberOfRecords;
  }

  /**
   * Returns the current balance (i.e. not including balance changes caused by
   * open leave requests) for the given Contacts during the given Absence Period.
   * Optionally, it can return balances only for a specific Absence Type.
   *
   * @param array $contactIDs
   * @param int $absencePeriodID
   * @param int|null $absenceTypeID
   *
   * @return array
   *  [
   *     contact_id_1 => [
   *        absence_type1_id => balance,
   *        absence_type2_id => balance,
   *        ...
   *     ],
   *     contact_id_2 => [
   *      absence_type1_id => balance,
   *      ...
   *     ]
   *     ...
   *  ]
   */
  public static function getBalanceForContacts($contactIDs, $absencePeriodID, $absenceTypeID = null) {
    $balances = [];

    $absencePeriod = AbsencePeriod::findById($absencePeriodID);

    array_walk($contactIDs, 'intval');

    $balanceChangeTable = self::getTableName();
    $leaveRequestDateTable = LeaveRequestDate::getTableName();
    $leaveRequestTable = LeaveRequest::getTableName();
    $contractTable = HRJobContract::getTableName();
    $contractRevisionTable = HRJobContractRevision::getTableName();
    $contractDetailsTable = HRJobDetails::getTableName();
    $periodEntitlementTable = LeavePeriodEntitlement::getTableName();

    $whereLeaveRequestAbsenceType = '';
    $wherePeriodEntitlementAbsenceType = '';
    if($absenceTypeID) {
      $absenceTypeID = (int)$absenceTypeID;
      $whereLeaveRequestAbsenceType = "leave_request.type_id = {$absenceTypeID} AND";
      $wherePeriodEntitlementAbsenceType = "period_entitlement.type_id = {$absenceTypeID} AND";
    }

    $query = "
        SELECT
           COALESCE(leave_request.contact_id, period_entitlement.contact_id) as contact_id,
           COALESCE(leave_request.type_id, period_entitlement.type_id) as type_id,
           SUM(leave_balance_change.amount) as balance
        FROM {$balanceChangeTable} leave_balance_change
          LEFT JOIN {$periodEntitlementTable} period_entitlement
            ON leave_balance_change.source_id = period_entitlement.id AND
               leave_balance_change.source_type = '" . self::SOURCE_ENTITLEMENT . "'
          LEFT JOIN {$leaveRequestDateTable} leave_request_date
            ON leave_balance_change.source_id = leave_request_date.id AND
               leave_balance_change.source_type = '" . self::SOURCE_LEAVE_REQUEST_DAY . "'
          LEFT JOIN {$leaveRequestTable} leave_request
            ON leave_request_date.leave_request_id = leave_request.id AND
               leave_request.is_deleted = 0
          LEFT JOIN {$contractTable} contract
            ON leave_request.contact_id = contract.contact_id
          LEFT JOIN {$contractRevisionTable} contract_revision
            ON contract_revision.id = (
            SELECT id
            FROM {$contractRevisionTable} contract_revision2
            WHERE contract_revision2.jobcontract_id = contract.id
            ORDER BY contract_revision2.effective_date DESC
            LIMIT 1
          )
          LEFT JOIN {$contractDetailsTable} contract_details
            ON contract_revision.details_revision_id = contract_details.jobcontract_revision_id

        WHERE ((
          {$whereLeaveRequestAbsenceType}
          leave_request.status_id IN(" . implode(', ', LeaveRequest::getApprovedStatuses()) . ") AND
          (leave_request_date.date >= %1 AND leave_request_date.date <= %2) AND
          contract.deleted = 0 AND
          (
            leave_request.from_date <= contract_details.period_end_date OR
            contract_details.period_end_date IS NULL
          ) AND
          (
            leave_request.to_date >= contract_details.period_start_date OR
            (leave_request.to_date IS NULL AND leave_request.from_date >= contract_details.period_start_date)
          ) AND
          leave_request.contact_id IN(". implode(', ', $contactIDs) .")
        ) OR (
            {$wherePeriodEntitlementAbsenceType}
            period_entitlement.contact_id IN(". implode(', ', $contactIDs) .") AND
            period_entitlement.period_id = %3
          )
        )
        GROUP BY contact_id, type_id
    ";

    $params = [
      1 => [$absencePeriod->start_date, 'String'],
      2 => [$absencePeriod->end_date, 'String'],
      3 => [$absencePeriodID, 'Positive']
    ];

    $result = CRM_Core_DAO::executeQuery($query, $params);

    while($result->fetch()) {
      $balances[$result->contact_id][$result->type_id] = $result->balance;
    }

    return $balances;
  }

  /**
   * Returns a list of balances for the open Leave Requests of the Contacts with
   * the given IDs during the given Absence Period. Optionally, it can return
   * only the balances for the given Absence Type.
   *
   * Open Leave Requests are those where the status is either "Awaiting Approval"
   * or "More Information Required".
   *
   * Given Leave Requests always deduct days, the returned value will be negative.
   *
   * @param array $contactIDs
   * @param int $absencePeriodID
   * @param int|null $absenceTypeID
   *
   * @return array
   *  An array with the following format:
   *  [
   *     contact_id_1 => [
   *        absence_type1_id => balance,
   *        absence_type2_id => balance,
   *        ...
   *     ],
   *     contact_id_2 => [
   *      absence_type1_id => balance,
   *      ...
   *     ]
   *     ...
   *  ]
   */
  public static function getOpenLeaveRequestBalanceForContacts($contactIDs, $absencePeriodID, $absenceTypeID = null) {
    $balances = [];

    $balanceChangeTable = self::getTableName();
    $leaveRequestDateTable = LeaveRequestDate::getTableName();
    $leaveRequestTable = LeaveRequest::getTableName();
    $contractTable = HRJobContract::getTableName();
    $contractRevisionTable = HRJobContractRevision::getTableName();
    $contractDetailsTable = HRJobDetails::getTableName();

    $absencePeriod = AbsencePeriod::findById($absencePeriodID);

    $whereAbsenceType = '';
    if($absenceTypeID) {
      $absenceTypeID = (int)$absenceTypeID;
      $whereAbsenceType = "leave_request.type_id = {$absenceTypeID} AND";
    }

    $query = "
      SELECT leave_request.contact_id, leave_request.type_id, SUM(leave_balance_change.amount) balance
      FROM {$balanceChangeTable} leave_balance_change
      INNER JOIN {$leaveRequestDateTable} leave_request_date
        ON leave_balance_change.source_id = leave_request_date.id AND
                 leave_balance_change.source_type = '" . self::SOURCE_LEAVE_REQUEST_DAY . "'
      INNER JOIN {$leaveRequestTable} leave_request
        ON leave_request_date.leave_request_id = leave_request.id AND
                 leave_request.is_deleted = 0
      INNER JOIN {$contractTable} contract
        ON leave_request.contact_id = contract.contact_id
      INNER JOIN {$contractRevisionTable} contract_revision
        ON contract_revision.id = (
          SELECT id FROM {$contractRevisionTable} contract_revision2
          WHERE contract_revision2.jobcontract_id = contract.id
          ORDER BY contract_revision2.effective_date DESC
          LIMIT 1
        )
      INNER JOIN {$contractDetailsTable} contract_details
        ON contract_revision.details_revision_id = contract_details.jobcontract_revision_id

      WHERE contract.deleted = 0 AND
        leave_request_date.date >= %1 AND
        leave_request_date.date <= %2 AND
        (
          leave_request.from_date <= contract_details.period_end_date OR
          contract_details.period_end_date IS NULL
        )  AND
        (
          leave_request.to_date >= contract_details.period_start_date OR
          (leave_request.to_date IS NULL AND leave_request.from_date >= contract_details.period_start_date)
        ) AND
        leave_balance_change.expired_balance_change_id IS NULL AND {$whereAbsenceType}
        leave_request.contact_id IN(" . implode(', ', $contactIDs) . ") AND
        leave_request.status_id IN(" . implode(', ', LeaveRequest::getOpenStatuses()) . ")
      GROUP BY leave_request.contact_id, leave_request.type_id
    ";

    $params = [
      1 => [$absencePeriod->start_date, 'String'],
      2 => [$absencePeriod->end_date, 'String'],
    ];
    $result = CRM_Core_DAO::executeQuery($query, $params);

    while($result->fetch()) {
      $balances[$result->contact_id][$result->type_id] = $result->balance;
    }

    return $balances;
  }

  /**
   * Returns a list of LeaveBalanceChange instances linked to the given
   * LeaveRequestDate instances.
   *
   * The results are indexed by the LeaveRequestDates ID.
   *
   * @param CRM_HRLeaveAndAbsences_BAO_LeaveRequestDate[]
   *
   * @return CRM_HRLeaveAndAbsences_BAO_LeaveBalanceChange[]
   */
  public static function getForLeaveRequestDates($dates) {
    $balanceChanges = [];

    $datesIDs = CRM_Utils_Array::collect('id', $dates);

    $balanceChange = new self();
    $balanceChange->source_type = self::SOURCE_LEAVE_REQUEST_DAY;
    $balanceChange->whereAdd('source_id IN (' . implode(',', $datesIDs) . ')');
    $balanceChange->find();

    while($balanceChange->fetch()) {
      $balanceChanges[$balanceChange->source_id] = clone $balanceChange;
    }

    return $balanceChanges;
  }
}

<?php

use CRM_Contact_BAO_Relationship as Relationship;
use CRM_Contact_BAO_RelationshipType as RelationshipType;

class CRM_HRLeaveAndAbsences_ACL_LeaveApprover {

  use CRM_HRLeaveAndAbsences_ACL_LeaveInformationTrait;

  public function addRules($type, &$tables, &$whereTables, &$contactID, &$where) {
    if (!$contactID) {
      return;
    }

    $this->addTables($tables, $whereTables);
    $this->addWhere($where);
  }

  private function addTables(&$tables, &$whereTables) {
    $relationshipTable = Relationship::getTableName();
    $relationshipTypeTable = RelationshipType::getTableName();

    $tables['r'] = $whereTables['r'] = "LEFT JOIN {$relationshipTable} r ON contact_a.id = r.contact_id_a";
    $tables['rt'] = $whereTables['rt'] = "LEFT JOIN {$relationshipTypeTable} rt ON rt.id = r.relationship_type_id";
  }

  private function addWhere(&$where) {
    $aclWhereConditions = $this->getLeaveApproverRelationshipWhereClause();
    $where = trim($where) ? "(({$where}) OR ({$aclWhereConditions}))" : $aclWhereConditions;
  }

}

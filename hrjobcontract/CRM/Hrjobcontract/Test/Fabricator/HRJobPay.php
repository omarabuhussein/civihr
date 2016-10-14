<?php

class CRM_Hrjobcontract_Test_Fabricator_HRJobPay {

  private static $default = [
    'sequential' => 1
  ];

  public static function fabricate($params) {
    if (!isset($params['jobcontract_id'])) {
      throw new Exception('Specify jobcontract_id value');
    }

    $result = civicrm_api3(
      'HRJobPay',
      'create',
      array_merge(self::$default, $params)
    );

    return $result['values'][0];
  }
}

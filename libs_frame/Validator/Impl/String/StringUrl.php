<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2017-3-26
 * Time: 1:09
 */
namespace Validator\Impl\String;

use Constant\Server;
use Validator\Base\BaseValidator;
use Validator\ValidatorService;

class StringUrl extends BaseValidator implements ValidatorService {
    public function __construct() {
        parent::__construct();
        $this->validatorType = Server::VALIDATOR_STRING_TYPE_URL;
    }

    private function __clone() {
    }

    public function validator($data, $compareData) : string {
        if ($data === null) {
            return '';
        }

        $trueData = $this->verifyStringData($data);
        if ($trueData === null) {
            return '必须是字符串';
        } else if(preg_match('/^(http|https|ftp)\:\/\/\S+$/', $trueData) > 0){
            return '';
        } else {
            return '格式必须是URL地址';
        }
    }
}
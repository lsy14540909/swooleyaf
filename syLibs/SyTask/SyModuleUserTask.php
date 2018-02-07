<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2017/12/10
 * Time: 13:32
 */
namespace SyTask;

use Constant\Server;

class SyModuleUserTask extends SyModuleTaskBase implements SyModuleTaskInterface {
    public function __construct() {
        parent::__construct();
        $this->moduleTag = Server::MODULE_NAME_USER;
    }

    private function __clone() {
    }

    public function handleTask(array $data) {
        if($data['wxcache_refresh']){
            foreach ($data['wxcaches'] as $eCache) {
                $this->handleRefreshWxCache([
                    'app_id' => $eCache['appid'],
                    'access_token' => $eCache['accesstoken'],
                    'js_ticket' => $eCache['jsticket'],
                    'projects' => $data['projects'],
                ], '');
            }
        }

        if($data['clear_localuser']){ //清除本地用户信息缓存
            $this->clearLocalUserCache([
                'projects' => $data['projects'],
            ], '');
        }
    }
}
<?php

namespace app\admin\controller\wanlshop;

use app\common\controller\Backend;
use app\common\service\BdPromoterService;
use think\Db;
use think\Exception;

use think\exception\PDOException;
use think\exception\ValidateException;

/**
 * 认证管理
 *
 * @icon fa fa-circle-o
 */
class Auth extends Backend
{
    
    /**
     * Auth模型对象
     * @var \app\admin\model\wanlshop\Auth
     */
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\wanlshop\Auth;
        $this->view->assign("stateList", $this->model->getStateList());
        $this->view->assign("verifyList", $this->model->getVerifyList());
        $this->view->assign("statusList", $this->model->getStatusList());
    }
    
	/**
	 * 详情
	 */
	public function detail($ids = null)
	{
		$row = $this->model->get($ids);
		if (!$row) {
		    $this->error(__('No Results were found'));
		}
		$this->view->assign("row", $row);
		return $this->view->fetch();
	}
	
	/**
	 * 同意
	 */
	public function agree($ids = null)
	{
		$row = $this->model->get($ids);
		if (!$row) {
		    $this->error(__('No Results were found'));
		}
		$adminIds = $this->getDataLimitAdminIds();
		if (is_array($adminIds)) {
		    if (!in_array($row[$this->dataLimitField], $adminIds)) {
		        $this->error(__('You have no permission'));
		    }
		}
		if ($row['verify'] == 3) {
		    // $this->error(__('已审核过本店铺，请不要重复审核！'));
		}
		if ($this->request->isPost()) {
			$result = false;
			Db::startTrans();
			try {
			    //是否采用模型验证
			    if ($this->modelValidate) {
			        $name = str_replace("\\model\\", "\\validate\\", get_class($this->model));
			        $validate = is_bool($this->modelValidate) ? ($this->modelSceneValidate ? $name . '.edit' : $name) : $this->modelValidate;
			        $row->validateFailException(true)->validate($validate);
			    }
				// 审核通过
			    $result = $row->allowField(true)->save(['verify' => 3]);
				if($row['verify'] != 4){
					// 新增店铺
					$shop = model('app\admin\model\wanlshop\Shop');
					$shop->user_id = $row['user_id'];
					$shop->state = $row['state'];
					$shop->shopname = $row['shopname'];
					$shop->avatar = $row['avatar'];
					$shop->bio = $row['content'];
					$shop->description = $row['bio'];
					$shop->city = $row['city'];
					$shop->delivery_city_code = $row['delivery_city_code'];
					$shop->delivery_city_name = $row['delivery_city_name'];
					$shop->verify = $row['verify'];

					// 【新增】同步邀请人信息到店铺表
					if (!empty($row['invite_code'])) {
					    $inviter = Db::name('user')
					        ->where('invite_code', $row['invite_code'])
					        ->field('id')
					        ->find();
					    if ($inviter && $inviter['id'] != $row['user_id']) {
					        $shop->inviter_id = $inviter['id'];
					        $shop->invite_bind_time = time();
					    }
					}

					// 【新增】同步BD推广员信息到店铺表
					$bderId = null;
					if (!empty($row['bd_code'])) {
					    $bdService = new BdPromoterService();
					    $bdUser = $bdService->validateBdCode($row['bd_code'], $row['user_id']);
					    if ($bdUser) {
					        $shop->bder_id = $bdUser['id'];
					        $shop->bder_bind_time = time();
					        $bderId = $bdUser['id'];
					    }
					}

					// 新增店铺配置
					if($shop->save()){
						$config = model('app\index\model\wanlshop\ShopConfig');
						$config->shop_id = $shop->id;
						$result = $config->save();

						// 【店铺邀请升级】审核通过时触发邀请人升级
						// 注意：inviter_id 可能未设置（无邀请码或邀请码无效时），需安全获取
						$inviterId = isset($shop->data['inviter_id']) ? $shop->data['inviter_id'] : null;
						if ($inviterId) {
						    $this->processShopInviterUpgrade($inviterId, $shop->id);
						}

						// 【新增】BD推广员店铺绑定处理
						if ($bderId) {
						    try {
						        $bdService = new BdPromoterService();
						        $bdService->onShopBind($bderId, $shop->id, $row['user_id']);
						    } catch (\Exception $e) {
						        // 记录日志但不影响审核流程
						        \think\Log::error('BD店铺绑定处理失败: ' . $e->getMessage());
						    }
						}
					}
				}
			    Db::commit();
			} catch (ValidateException $e) {
			    Db::rollback();
			    $this->error($e->getMessage());
			} catch (PDOException $e) {
			    Db::rollback();
			    $this->error($e->getMessage());
			} catch (Exception $e) {
			    Db::rollback();
			    $this->error($e->getMessage());
			}
			if ($result !== false) {
			    $this->success();
			} else {
			    $this->error(__('No rows were updated'));
			}
		}
		$this->view->assign("row", $row);
		return $this->view->fetch();
	}
	
	/**
	 * 拒绝
	 */
	public function refuse($ids = null)
	{
		$row = $this->model->get($ids);
		if (!$row) {
		    $this->error(__('No Results were found'));
		}
		$adminIds = $this->getDataLimitAdminIds();
		if (is_array($adminIds)) {
		    if (!in_array($row[$this->dataLimitField], $adminIds)) {
		        $this->error(__('You have no permission'));
		    }
		}
		if ($this->request->isPost()) {
		    $params = $this->request->post("row/a");
		    if ($params) {
		        $params = $this->preExcludeFields($params);
		        $result = false;
		        Db::startTrans();
		        try {
		            //是否采用模型验证
		            if ($this->modelValidate) {
		                $name = str_replace("\\model\\", "\\validate\\", get_class($this->model));
		                $validate = is_bool($this->modelValidate) ? ($this->modelSceneValidate ? $name . '.edit' : $name) : $this->modelValidate;
		                $row->validateFailException(true)->validate($validate);
		            }
					$params['verify'] = 4;
		            $result = $row->allowField(true)->save($params);
		            Db::commit();
		        } catch (ValidateException $e) {
		            Db::rollback();
		            $this->error($e->getMessage());
		        } catch (PDOException $e) {
		            Db::rollback();
		            $this->error($e->getMessage());
		        } catch (Exception $e) {
		            Db::rollback();
		            $this->error($e->getMessage());
		        }
		        if ($result !== false) {
		            $this->success();
		        } else {
		            $this->error(__('No rows were updated'));
		        }
		    }
		    $this->error(__('Parameter %s can not be empty', ''));
		}
		$this->view->assign("row", $row);
		return $this->view->fetch();
	}

	/**
	 * 店铺邀请触发邀请人升级
	 *
	 * 当店铺审核通过时，如果店铺有邀请人且邀请人等级<2，则升级邀请人
	 *
	 * @param int $inviterId 邀请人用户ID
	 * @param int $shopId 店铺ID
	 */
	protected function processShopInviterUpgrade($inviterId, $shopId)
	{
	    // 检查是否已升级过（幂等）
	    $existsUpgrade = Db::name('shop_invite_upgrade_log')
	        ->where('user_id', $inviterId)
	        ->where('shop_id', $shopId)
	        ->find();
	    if ($existsUpgrade) {
	        return;
	    }

	    // 获取邀请人信息并加锁
	    $inviter = Db::name('user')
	        ->where('id', $inviterId)
	        ->lock(true)
	        ->field('id, bonus_level, bonus_ratio')
	        ->find();
	    if (!$inviter) {
	        return;
	    }

	    $currentLevel = (int)$inviter['bonus_level'];
	    if ($currentLevel >= 2) {
	        return; // 已满级，不升级
	    }

	    // 计算升级后的等级和比例
	    $afterLevel = $currentLevel + 1;
	    $ratios = $this->getInviteRatios();
	    $beforeRatio = (float)$inviter['bonus_ratio'];
	    $afterRatio = isset($ratios[$afterLevel]) ? (float)$ratios[$afterLevel] : $beforeRatio;

	    $now = time();

	    // 更新邀请人等级
	    Db::name('user')->where('id', $inviterId)->update([
	        'bonus_level' => $afterLevel,
	        'bonus_ratio' => $afterRatio
	    ]);

	    // 记录升级日志
	    Db::name('shop_invite_upgrade_log')->insert([
	        'user_id' => $inviterId,
	        'shop_id' => $shopId,
	        'verification_id' => 0, // 注册时升级，无核销记录
	        'voucher_id' => 0,
	        'before_level' => $currentLevel,
	        'after_level' => $afterLevel,
	        'before_ratio' => $beforeRatio,
	        'after_ratio' => $afterRatio,
	        'createtime' => $now
	    ]);
	}

	/**
	 * 获取返利比例配置
	 *
	 * @return array
	 */
	protected function getInviteRatios()
	{
	    return [
	        0 => (float)config('site.invite_base_ratio') ?: 1.0,
	        1 => (float)config('site.invite_level1_ratio') ?: 1.5,
	        2 => (float)config('site.invite_level2_ratio') ?: 2.0,
	    ];
	}

}

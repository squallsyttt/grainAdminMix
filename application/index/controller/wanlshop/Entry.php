<?php
// 2020年2月17日21:42:31
namespace app\index\controller\wanlshop;

use app\common\controller\Frontend;
use fast\Http;
use think\Db;
/**
 * 审核
 * @internal
 */
class Entry extends Frontend
{
    protected $noNeedLogin = '';
    protected $noNeedRight = '*';
    protected $layout = 'default';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\index\model\wanlshop\Auth;
		// 获取用户下的申请
        $this->entry = $this->model->where(['user_id' => $this->auth->id])->find();
        $this->view->assign("entry", $this->entry);
		$this->assignconfig("entry", $this->entry);
		// 获取用户手机号码
        $this->view->assign("mobile", $this->auth->mobile);
    }

    // 提交资质
    public function index()
    {
        if ($this->request->isPost()) {
            $data = $this->request->post();

            // 【新增】检查统一信用代码是否已被使用
            $number = trim($data['number'] ?? '');
            if (!empty($number)) {
                // 查询是否有其他用户使用过该统一信用代码（包括软删除的记录）
                $existingAuth = Db::name('wanlshop_auth')
                    ->where('number', $number)
                    ->where('user_id', '<>', $this->auth->id)
                    ->field('id, user_id, deletetime')
                    ->find();

                if ($existingAuth) {
                    // 检查该用户是否有被软删除的店铺
                    $existingShop = Db::name('wanlshop_shop')
                        ->where('user_id', $existingAuth['user_id'])
                        ->field('id, deletetime')
                        ->find();

                    if ($existingShop && $existingShop['deletetime']) {
                        // 店铺被软删除，说明被平台管控
                        $this->error('您之前被平台管控，请联系客服处理');
                    } else {
                        // 正常情况，统一信用代码已被使用
                        $this->error('该统一信用代码已被注册，不允许重复注册');
                    }
                }

                // 【补充】检查当前用户自己是否有被软删除的店铺（恢复注册场景）
                $myShop = Db::name('wanlshop_shop')
                    ->where('user_id', $this->auth->id)
                    ->field('id, deletetime')
                    ->find();
                if ($myShop && $myShop['deletetime']) {
                    $this->error('您之前被平台管控，请联系客服处理');
                }
            }

			$data['verify'] = '1';
			$data['user_id'] = $this->auth->id;
			$result = $this->entry ? $this->entry->allowField(true)->save($data) : $this->model->allowField(true)->save($data);
			$this->success();
			// $result ? $this->success() : $this->error(__('提交失败'));
        }
		// 如果已经提交过了
		if($this->entry){
			if ($this->entry->verify == '2' || $this->entry->verify == '3') {
			    header('Location:' .url('/index/wanlshop/entry/stepfour'));
				exit;
			}
		}
		$this->view->assign('title', '商家入驻 - 步骤2 提交资质');
		return $this->view->fetch();
    }

    // 提交店铺信息
    public function stepthree()
    {
        if ($this->request->isPost()) {
            $data = $this->request->post();
            $config = get_addon_config('wanlshop');
            $verify = $config['config']['store_audit'] == 'N' ? 3:2;

            // 【新增】验证并处理邀请码
            $inviterId = null;
            $inviteCode = strtoupper(trim($data['invite_code'] ?? ''));
            if (!empty($inviteCode)) {
                // 查询邀请码对应的用户
                $inviter = Db::name('user')
                    ->where('invite_code', $inviteCode)
                    ->field('id')
                    ->find();
                if (!$inviter) {
                    $this->error('邀请码无效');
                }
                // 不能绑定自己
                if ($inviter['id'] == $this->auth->id) {
                    $this->error('不能使用自己的邀请码');
                }
                $inviterId = $inviter['id'];
            }

            // 更新提交信息（包含邀请码暂存）
            $data['user_id'] = $this->auth->id;
            $data['verify'] = $verify;
            $data['invite_code'] = $inviteCode; // 暂存邀请码到 auth 表
			$this->entry ? $this->entry->allowField(true)->save($data) : $this->model->allowField(true)->save($data);

			// 自动审核
			if($config['config']['store_audit'] == 'N'){
			    $row = model('app\index\model\wanlshop\Auth')->where(['user_id' => $this->auth->id])->find();
				// 新增店铺
				$shop = model('app\index\model\wanlshop\Shop');
				$shop->user_id = $this->auth->id;
				$shop->state = $row['state'];
				$shop->shopname = $row['shopname'];
				$shop->avatar = $row['avatar'];
				$shop->bio = $row['content'];
				$shop->description = $row['bio'];
				$shop->city = $row['city'];
				$shop->delivery_city_code = $row['delivery_city_code'];
				$shop->delivery_city_name = $row['delivery_city_name'];
				$shop->verify = $verify;
				// 【新增】绑定邀请人
				if ($inviterId) {
				    $shop->inviter_id = $inviterId;
				    $shop->invite_bind_time = time();
				}
				$shop->save();
			}
			$this->success();
			// $result ? $this->success() : $this->error(__('提交失败'));
        }
		$this->view->assign('title', '商家入驻 - 步骤3 提交店铺信息');
		return $this->view->fetch();
    }

    // 提交审核
    public function stepfour()
    {
        $this->view->assign('title', '商家审核');
        return $this->view->fetch();
    }
}

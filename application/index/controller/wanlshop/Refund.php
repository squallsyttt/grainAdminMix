<?php
namespace app\index\controller\wanlshop;

use app\common\controller\Wanlshop;
use addons\wanlshop\library\WanlChat\WanlChat;

use think\Db;
use think\Exception;

use think\exception\PDOException;
use think\exception\ValidateException;

/**
 * 订单退款管理
 *
 * @icon fa fa-circle-o
 */
class Refund extends Wanlshop
{
    protected $noNeedLogin = '';
    protected $noNeedRight = '*';
    /**
     * Refund模型对象
     * @var \app\index\model\wanlshop\Refund
     */
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\index\model\wanlshop\Refund;
		$this->wanlchat = new WanlChat();
        $this->view->assign("expresstypeList", $this->model->getExpresstypeList());
        $this->view->assign("typeList", $this->model->getTypeList());
        $this->view->assign("reasonList", $this->model->getReasonList());
        $this->view->assign("stateList", $this->model->getStateList());
        $this->view->assign("statusList", $this->model->getStatusList());
    }
    
    
    /**
     * 查看
     */
    public function index()
    {
        //当前是否为关联查询
        $this->relationSearch = true;
        //设置过滤方法
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax())
        {
            //如果发送的来源是Selectpage，则转发到Selectpage
            if ($this->request->request('keyField'))
            {
                return $this->selectpage();
            }
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $total = $this->model
                    ->with(['order','pay'])
                    ->where($where)
                    ->order($sort, $order)
                    ->count();
    
            $list = $this->model
                    ->with(['order','pay'])
                    ->where($where)
                    ->order($sort, $order)
                    ->limit($offset, $limit)
                    ->select();
    
            foreach ($list as $row) {
				if($row['order_type'] === 'groups'){
					$row->groupsgoods->visible(['title','image']);
				}else{
					$row->goods->visible(['title','image']);
				}
                $row->getRelation('order')->visible(['id']);
    			$row->getRelation('pay')->visible(['pay_no']);
            }
            $list = collection($list)->toArray();
            $result = array("total" => $total, "rows" => $list);
    
            return json($result);
        }
        return $this->view->fetch();
    }
	
	/**
	 * 退款详情 1.1.3升级
	 */
	public function detail($ids = null, $order_id = null, $order_no = null)
	{
		$where = [];
		if($ids){
			$where['id'] = $ids;
			$where['shop_id'] = $this->shop->id;
		}
		if($order_id){
			$where['order_id'] = $order_id;
			$where['shop_id'] = $this->shop->id;
		}
		if($order_no){
			$order = model('app\api\model\wanlshop\Order')
				->where(['order_no' => $order_no, 'user_id' => $this->auth->id])
				->find();
			$where['order_id'] = $order['id'];
		}
		$row = $this->model
			->where($where)
			->find();
		if (!$row) {
		    $this->error(__('No Results were found'));
		}
		$row['images'] = explode(',', $row['images']);
		if($row['order_type'] === 'groups'){
			$row['ordergoods'] = model('app\index\model\wanlshop\groups\OrderGoods')
				->where('id', 'in', $row['goods_ids'])
				->where('shop_id', $row['shop_id'])
				->select();
		}else{
			$row['ordergoods'] = model('app\index\model\wanlshop\OrderGoods')
				->where('id', 'in', $row['goods_ids'])
				->where('shop_id', $row['shop_id'])
				->select();
		}
		$row['log'] = model('app\index\model\wanlshop\RefundLog')
			->where(['refund_id' => $row['id']])
			->order('createtime desc')
			->select();
	    $this->view->assign("row", $row);
		return $this->view->fetch();
	}
	
	/**
	 * 同意退款
	 */
	public function agree($ids = null)
	{
		$row = $this->model->get($ids);
		if (!$row) {
		    $this->error(__('No Results were found'));
		}
		if ($row['shop_id'] !=$this->shop->id) {
		    $this->error(__('You have no permission'));
		}
		if ($row['state'] == 2 || $row['state'] == 3 || $row['state'] == 4 || $row['state'] == 5) {
			$this->error(__('当前状态，不可操作'));
		}
		// 判断金额
		if(number_format($row['price'], 2) > number_format($row->pay->price, 2)){
		 	$this->error(__('非法退款金额，金额超过订单金额！！请拒绝退款！！'));
		}
		// 退款类型 1.1.6升级
		$refundType = 1;
		$result = false;
		$error = '';
		Db::startTrans();
		try {
			$data = [];
			// 判断退款类型 我要退款(无需退货)
			if($row['type'] == 0){
				$refund_status = 3;
				$data['state'] = 4; // 退款完成
				$data['completetime'] = time(); // 完成退款 时间
				// 退款日志
				$refundLog = '卖家同意退款，'.$row['price'].'元退款到买家账号余额';
				// 推送标题
				$pushLog = '退款已完成';
				// 订单支付
				$orderPay = false;
				// 判断业务类型
				if($row['order_type'] === 'groups'){
					// 查询订单是已确定收货
					$order = model('app\index\model\wanlshop\groups\Order')->get($row['order_id']);
					// 订单状态:1=待支付,2=待成团,3=待发货,4=待收货,5=待评论,6=已完成,7=已取消
					$orderPay = $order['state'] == 5 ? true : false;
				}else{
					$order = model('app\index\model\wanlshop\Order')->get($row['order_id']);
					// 订单状态:1=待支付,2=待发货,3=待收货,4=待评论,5=已弃用,6=已完成,7=已取消
					$orderPay = $order['state'] == 4 ? true : false;
				}
				// 更新钱包 1.此订单如果已确认收货扣商家 2.此订单没有确认收货，平台退款
				if($orderPay){
					// 扣商家
					controller('addons\wanlshop\library\WanlPay\WanlPay')->money(-$row['price'], $order['shop']['user_id'], '确认收货，同意退款', 'refund', $order['order_no']);
				}
				// 退款给用户 (如果第三方退款提交成功后，扣除此款项)
				controller('addons\wanlshop\library\WanlPay\WanlPay')->money(+$row['price'], $row['user_id'], '卖家同意退款', 'refund', $order['order_no']);
				// 1.1.5升级 第三方退款
				$config = get_addon_config('wanlshop');
				// 检查是否原路返还
				if($config['config']['refund_switch'] == 'Y')
				{
					$refundType = 3;
					$wanlpay = controller('addons\wanlshop\library\WanlPay\WanlPay')->refund($row['id'], $row['price'], $row['order_pay_id']);
					// code=0同步方法：refund判断fund_change = Y，代表退款成功，支付宝文档《如何判断退款是否成功》，https://opendocs.alipay.com/support/01rawa
					if($wanlpay['code'] == 0)
					{
						// 退款中
						$data['state'] = 4; 
						// 退款日志
						$refundLog = $wanlpay['data']['type_text'].'已将退款￥'.$wanlpay['data']['money'].'元原路返还买家'.$wanlpay['data']['type_text'].'账户';
						// 推送日志
						$pushLog = $wanlpay['data']['type_text'].'退款成功';
						// 扣除本地用户余额
						controller('addons\wanlshop\library\WanlPay\WanlPay')->money(-$wanlpay['data']['money'], $wanlpay['data']['user_id'], '退款订单（订单号：'.$order['order_no'].'）已原路返回余额至你的'.$wanlpay['data']['type_text'], 'sys');
					// 如果退款成功,修改退款状态为第三方退款中，等待回调,否则退款到余额
					}else if($wanlpay['code'] == 200){
						// 退款中
						$data['state'] = 7; 
						// 退款日志
						$refundLog = '已提交'.$wanlpay['data']['type_text'].'处理，预计24小时内将退款￥'.$wanlpay['data']['money'].'元原路返还买家'.$wanlpay['data']['type_text'].'账户';
						// 推送日志
						$pushLog = $wanlpay['data']['type_text'].'正在处理您的退款预计24小时内到账';
						// 扣除本地用户余额
						controller('addons\wanlshop\library\WanlPay\WanlPay')->money(-$wanlpay['data']['money'], $wanlpay['data']['user_id'], '退款订单（订单号：'.$order['order_no'].'）已原路返回余额至你的'.$wanlpay['data']['type_text'], 'sys');
					// 支付宝提交退款,返回fund_change=N时,在此中断
					// 1.1.6升级
					}else if($wanlpay['code'] == 1){
						$refundLog = '卖家同意退款，'.$row['price'].'元退款到买家账号余额';
					}else{
						$refundLog = '卖家同意退款，第三方支付退款失败'.$row['price'].'元退款到买家账号余额，请手动提现';
					}
					
				}
				//后续版本推送订购单
				// ...
			}else if($row['type'] == 1){
				$refund_status = 2;
				$data['state'] = 1; // 先同意退款，还需要买家继续退货
				$data['agreetime'] = time(); // 卖家同意 时间
				$refundLog = '卖家同意退货申请';
				// 推送标题
				$pushLog = '卖家同意退货';
				
			}else{
				$error = '非法退款类型，请拒绝退款！';
			}
			if(!$error){
				// 只有本地余额退款状态4,和先同意退款,需要商家继续退款状态1执行,状态7原路返回在回调更新状态
				if($data['state'] == 1 || $data['state'] == 4){
					// 1.0.5 更新商品状态
					$this->setOrderGoodsState($refund_status, $row['goods_ids'], $row['order_type']);
					// 更新订单状态
					$this->setRefundState($row['order_id'], $row['order_type']);
				}
				// 写入日志
				$this->refundLog($row['user_id'], $ids, $refundLog, $refundType);
				// 推送开始
				$this->pushRefund($row['id'], $row['order_id'], $row['goods_ids'], $pushLog, $row['order_type']);
				// 更新退款
				$row->allowField(true)->save($data);
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
		if (!$error) {
		    $this->success();
		} else {
		    $this->error($error);
		}
	}
	
	/**
	 * 确认收货 1.1.6升级
	 */
	public function receiving($ids = null)
	{
		$row = $this->model->get($ids);
		if (!$row) {
		    $this->error(__('No Results were found'));
		}
		if ($row['shop_id'] != $this->shop->id) {
		    $this->error(__('You have no permission'));
		}
		if ($row['state'] == 2 || $row['state'] == 3 || $row['state'] == 4 || $row['state'] == 5) {
			$this->error(__('当前状态，不可操作'));
		}
		// 退款日志
		$refundLog = '卖家确认收到退货，并将'.$row['price'].'元退款到买家账号余额';
		$refundType = 1;
		// 推送日志
		$pushLog = '退款已完成';
		$result = false;
		Db::startTrans();
		try {
			$data = [];
			$data['state'] = 4; // 退款完成
			$data['completetime'] = time(); // 完成退款 时间
			// 判断退款类型
			if($row['type'] == 1){
				// 判断金额
				if($row['price'] > $row->pay->price){
					throw new Exception("非法退款金额，金额超过订单金额！！请拒绝退款！！");
				}
			}else{
				throw new Exception("非法退款类型，请拒绝退款！");
			}
			// 判断业务类型
			if($row['order_type'] === 'groups'){
				$orderModel = model('app\index\model\wanlshop\groups\Order');
			}else{
				$orderModel = model('app\index\model\wanlshop\Order');
			}
			// 查询订单是已确定收货
			$order = $orderModel->get($row['order_id']);
			// 更新钱包
			// 1.此订单如果已确认收货扣商家
			// 2.此订单没有确认收货，平台退款	
			if($order['state'] == 4){
				// 扣商家
				controller('addons\wanlshop\library\WanlPay\WanlPay')->money(-$row['price'], $order['shop']['user_id'], '确认收货，同意退款', 'refund', $order['order_no']);
			}
			// 退款给用户 (如果第三方退款提交成功后，扣除此款项)
			controller('addons\wanlshop\library\WanlPay\WanlPay')->money(+$row['price'], $row['user_id'], '卖家同意退款', 'refund', $order['order_no']);
			// 1.1.5升级 第三方退款
			$config = get_addon_config('wanlshop');
			// 检查是否原路返还
			if($config['config']['refund_switch'] == 'Y')
			{
				$refundType = 3;
				$wanlpay = controller('addons\wanlshop\library\WanlPay\WanlPay')->refund($row['id'], $row['price'], $row['order_pay_id']);
				// code=0同步方法：refund判断fund_change = Y，代表退款成功，支付宝文档《如何判断退款是否成功》，https://opendocs.alipay.com/support/01rawa
				if($wanlpay['code'] == 0)
				{
					// 退款中
					$data['state'] = 4; 
					// 退款日志
					$refundLog = $wanlpay['data']['type_text'].'已将退款￥'.$wanlpay['data']['money'].'元原路返还买家'.$wanlpay['data']['type_text'].'账户';
					// 推送日志
					$pushLog = $wanlpay['data']['type_text'].'退款成功';
					// 扣除本地用户余额
					controller('addons\wanlshop\library\WanlPay\WanlPay')->money(-$wanlpay['data']['money'], $wanlpay['data']['user_id'], '退款订单（订单号：'.$order['order_no'].'）已原路返回余额至你的'.$wanlpay['data']['type_text'], 'sys');
				// 如果退款成功,修改退款状态为第三方退款中，等待回调,否则退款到余额
				}else if($wanlpay['code'] == 200){
					// 退款中
					$data['state'] = 7; 
					// 退款日志
					$refundLog = '已提交'.$wanlpay['data']['type_text'].'处理，预计24小时内将退款￥'.$wanlpay['data']['money'].'元原路返还买家'.$wanlpay['data']['type_text'].'账户';
					// 推送日志
					$pushLog = $wanlpay['data']['type_text'].'正在处理您的退款预计24小时内到账';
					// 扣除本地用户余额
					controller('addons\wanlshop\library\WanlPay\WanlPay')->money(-$wanlpay['data']['money'], $wanlpay['data']['user_id'], '退款订单（订单号：'.$order['order_no'].'）已原路返回余额至你的'.$wanlpay['data']['type_text'], 'sys');
				// 1.1.6升级
				}else if($wanlpay['code'] == 1){
					$refundLog = '卖家确认收到退货，并将'.$row['price'].'元退款到买家账号余额';
				}else{
					$refundLog = '卖家同意退款，第三方支付退款失败'.$row['price'].'元退款到买家账号余额，请手动提现';
				}
			}
			// 只有本地余额退款状态4,状态7原路返回在回调更新状态
			if($data['state'] == 4){
				// 更新商品状态
				$this->setOrderGoodsState(3, $row['goods_ids'], $row['order_type']);
				// 更新订单状态
				$this->setRefundState($row['order_id'], $row['order_type']);
			}
			// 写入日志
			$this->refundLog($row['user_id'], $ids, $refundLog, $refundType);
			// 推送开始
			$this->pushRefund($row['id'], $row['order_id'], $row['goods_ids'], $pushLog, $row['order_type']);
			// 更新退款
			$result = $row->allowField(true)->save($data);
			//后续版本推送订购单
			// ...
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
	
	/**
	 * 拒绝退款
	 */
	public function refuse($ids = null)
	{
		$row = $this->model->get($ids);
		if (!$row) {
		    $this->error(__('No Results were found'));
		}
		if ($row['shop_id'] != $this->shop->id) {
		    $this->error(__('You have no permission'));
		}
		if ($row['state'] != 0) {
			$this->error(__('当前状态，不可操作'));
		}
		if ($this->request->isPost()) {
		    $params = $this->request->post("row/a");
		    if ($params) {
		        $result = false;
		        Db::startTrans();
		        try {
					$params['state'] = 2;
					// 写入日志
					$this->refundLog($row['user_id'], $row['id'], '卖家拒绝了您的退款申请，拒绝理由：'.$params['refuse_content']);
					// 更新商品状态
					$this->setOrderGoodsState(5, $row['goods_ids'], $row['order_type']);
					// 更新订单状态
					$this->setRefundState($row['order_id'], $row['order_type']);
					// 推送开始
					$this->pushRefund($row['id'], $row['order_id'], $row['goods_ids'], '退款申请被拒绝', $row['order_type']);
					// 更新退款
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
	 * 推送退款消息（方法内使用）
	 *
	 * @param string refund_id 订单ID
	 * @param string order_id 订单ID
	 * @param string goods_id 订单ID
	 * @param string title 标题
	 */
	private function pushRefund($refund_id = 0, $order_id = 0, $goods_id = 0, $title = '', $order_type = 'goods')
	{
		if($order_type === 'groups'){
			$orderModel = model('app\index\model\wanlshop\groups\Order');
			$orderGoodsModel = model('app\index\model\wanlshop\groups\OrderGoods');
		}else{
			$orderModel = model('app\index\model\wanlshop\Order');
			$orderGoodsModel = model('app\index\model\wanlshop\OrderGoods');
		}
		$order = $orderModel->get($order_id);
		$goods = $orderGoodsModel->get($goods_id);
		$msg = [
			'user_id' => $order['user_id'], // 推送目标用户
			'shop_id' => $this->shop->id, 
			'title' => $title,  // 推送标题
			'image' => $goods['image'], // 推送图片
			'content' => '您申请退款的商品 '.(mb_strlen($goods['title'],'utf8') >= 25 ? mb_substr($goods['title'],0,25,'utf-8').'...' : $goods['title']).' '.$title, 
			'type' => 'order',  // 推送类型
			'modules' => $order_type === 'groups' ? 'groupsrefund' : 'refund',  // 模块类型
			'modules_id' => $refund_id,  // 模块ID
			'come' => '订单'.$order['order_no'] // 来自
		];
		$this->wanlchat->send($order['user_id'], $msg);
		$notice = model('app\index\model\wanlshop\Notice');
		$notice->data($msg);
		$notice->allowField(true)->save();
	}
	
	/**
	 * 更新订单商品状态（方法内使用）
	 *
	 * @ApiSummary  (WanlShop 更新订单商品状态)
	 * @ApiMethod   (POST)
	 * 
	 * @param string $status 状态
	 * @param string $goods_id 商品ID
	 */
	private function setOrderGoodsState($status = 0, $goods_id = 0, $order_type = 'goods')
	{
		if($order_type === 'groups'){
			$orderGoodsModel = model('app\index\model\wanlshop\groups\OrderGoods');
		}else{
			$orderGoodsModel = model('app\index\model\wanlshop\OrderGoods');
		}
		return $orderGoodsModel->save(['refund_status' => $status],['id' => $goods_id]);
	}
	
	
	/**
	 * 修改订单状态（方法内使用） 1.0.5升级
	 *
	 * @ApiSummary  (WanlShop 修改订单状态)
	 * @ApiMethod   (POST)
	 * 
	 * @param string $id 订单ID
	 */
	private function setRefundState($order_id = 0, $order_type = 'goods')
	{
		if($order_type === 'groups'){
			$orderModel = model('app\index\model\wanlshop\groups\Order');
			$orderGoodsModel = model('app\index\model\wanlshop\groups\OrderGoods');
		}else{
			$orderModel = model('app\index\model\wanlshop\Order');
			$orderGoodsModel = model('app\index\model\wanlshop\OrderGoods');
		}
		$list = $orderGoodsModel
			->where(['order_id' => $order_id])
			->select();
		$refundStatusCount = 0;
		foreach($list as $row){
			if($row['refund_status'] == 3) $refundStatusCount += 1;
		}
		// 如果订单下所有商品全部退款完毕则关闭订单
		if(count($list) == $refundStatusCount){
			$orderModel->save(['state'  => 7],['id' => $order_id]);
			return true;
		}
		return false;
	}
	
	/**
	 * 退款日志（方法内使用）
	 *
	 * @ApiSummary  (WanlShop 退款日志)
	 * 
	 * @param string $user_id 用户ID
	 * @param string $refund_id 退款ID
	 * @param string $content 日志内容
	 * @param string $type 退款状态:0=买家,1=卖家,2=官方,3=系统
	 */
	private function refundLog($user_id = 0, $refund_id = 0, $content = '', $type = 1)
	{
		return model('app\index\model\wanlshop\RefundLog')->allowField(true)->save([
			'shop_id' => $this->shop->id,
			'user_id' => $user_id,
			'refund_id' => $refund_id,
			'type' => $type,
			'content' => $content
		]);
	}
	
}

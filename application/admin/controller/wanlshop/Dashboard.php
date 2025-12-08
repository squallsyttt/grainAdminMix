<?php

namespace app\admin\controller\wanlshop;

use app\common\controller\Backend;
use think\Config;

/**
 * 控制台
 *
 * @icon fa fa-dashboard
 * @remark 用于展示当前系统中的统计数据、统计报表及重要实时数据
 */
class Dashboard extends Backend
{

    /**
     * 查看
     */
    public function index()
    {
        $user = model('app\common\model\User');
        $order = model('app\admin\model\wanlshop\Order');
        $goods = model('app\admin\model\wanlshop\Goods');
        $shop = model('app\admin\model\wanlshop\Shop');
        $shopauth = model('app\admin\model\wanlshop\Auth');
        $refund = model('app\admin\model\wanlshop\Refund');

        // 核销券相关模型
        $voucherOrder = model('app\admin\model\wanlshop\VoucherOrder');
        $voucher = model('app\admin\model\wanlshop\Voucher');
        $voucherVerification = model('app\admin\model\wanlshop\VoucherVerification');
        $voucherSettlement = model('app\admin\model\wanlshop\VoucherSettlement');
        $voucherRebate = model('app\admin\model\wanlshop\VoucherRebate');
        $voucherRefund = model('app\admin\model\wanlshop\VoucherRefund');
		// 处理POST
		if ($this->request->isPost()) {
		    $date = $this->request->post('date', '');
		    $type = $this->request->post('type', '');
		    if ($type == 'sale') {
		        list($orderSaleCategory, $orderSaleAmount, $orderSaleNums) = $this->statis($date);
		        $statistics = ['orderSaleCategory' => $orderSaleCategory, 'orderSaleAmount' => $orderSaleAmount, 'orderSaleNums' => $orderSaleNums];
		    }
		    $this->success('', '', $statistics);
		}
		// 店铺
		$this->view->assign("totalShop", $shop->where('verify','3')->count());
        // 用户
        $this->view->assign("totalUser", $user->count());
        $this->view->assign("totalDayUser", $user->whereTime('jointime', 'today')->count());
        // 商品
        $this->view->assign("totalGoods", $goods->count());

        // 时间范围
        $todayStart = strtotime('today');
        $todayEnd = strtotime('tomorrow') - 1;

        // 平台流水（仅核销券订单）
        $paidOrderStates = ['2','4']; // 已支付、存在退款
        $platformTotalAmount = $voucherOrder->where('state', 'in', $paidOrderStates)->sum('actual_payment');
        $platformTodayAmount = $voucherOrder
            ->where('state', 'in', $paidOrderStates)
            ->where('paymenttime', 'between', [$todayStart, $todayEnd])
            ->sum('actual_payment');

        // 订单统计（核销券）
        $totalVoucherOrder = $voucherOrder->count();
        $paidVoucherOrder = $voucherOrder->where('state', 'in', $paidOrderStates)->count();
        $unpaidVoucherOrder = $voucherOrder->where('state', '1')->count();
        $cancelVoucherOrder = $voucherOrder->where('state', '3')->count();
        $todayVoucherOrder = $voucherOrder->where('createtime', 'between', [$todayStart, $todayEnd])->count();

        // 券统计
        $totalVoucher = $voucher->count();
        $voucherUnused = $voucher->where('state', '1')->count();
        $voucherVerified = $voucher->where('state', '2')->count();
        $voucherExpired = $voucher->where('state', '3')->count();
        $voucherRefunded = $voucher->where('state', 'in', ['4','5'])->count();
        $voucherVerifiedAmount = $voucher->where('state', '2')->sum('face_value');
        $voucherVerifiedTodayCount = $voucher->where('state', '2')->where('verifytime', 'between', [$todayStart, $todayEnd])->count();
        $voucherVerifiedTodayAmount = $voucher->where('state', '2')->where('verifytime', 'between', [$todayStart, $todayEnd])->sum('face_value');

        // 核销记录（按核销表统计核销件数）
        $todayVerificationCount = $voucherVerification->where('createtime', 'between', [$todayStart, $todayEnd])->count();

        // 结算统计
        $settlementPendingCount = $voucherSettlement->where('state', '1')->count();
        $settlementPendingAmount = $voucherSettlement->where('state', '1')->sum('shop_amount');
        $settlementPaidCount = $voucherSettlement->where('state', '2')->count();
        $settlementPaidAmount = $voucherSettlement->where('state', '2')->sum('shop_amount');
        $settlementPayingCount = $voucherSettlement->where('state', '3')->count();
        $settlementFailedCount = $voucherSettlement->where('state', '4')->count();
        $platformProfitTotal = $voucherSettlement->sum('platform_amount');
        $platformProfitSettled = $voucherSettlement->where('state', '2')->sum('platform_amount');

        // 返利统计
        $rebateTotalAmount = $voucherRebate->sum('rebate_amount');
        $rebatePaidAmount = $voucherRebate->where('payment_status', 'paid')->sum('rebate_amount');
        $rebatePaidCount = $voucherRebate->where('payment_status', 'paid')->count();
        $rebatePendingAmount = $voucherRebate->where('payment_status', 'in', ['unpaid', 'pending', 'failed'])->sum('rebate_amount');
        $rebatePendingCount = $voucherRebate->where('payment_status', 'in', ['unpaid', 'pending', 'failed'])->count();
        $rebateFailedCount = $voucherRebate->where('payment_status', 'failed')->count();

        // 退款统计（核销券退款表）
        $refundApplyingCount = $voucherRefund->where('state', '0')->count();
        $refundAgreeCount = $voucherRefund->where('state', '1')->count();
        $refundRefuseCount = $voucherRefund->where('state', '2')->count();
        $refundSuccessCount = $voucherRefund->where('state', '3')->count();
        $refundSuccessAmount = $voucherRefund->where('state', '3')->sum('refund_amount');
        $refundTodayAmount = $voucherRefund->where('state', '3')->where('updatetime', 'between', [$todayStart, $todayEnd])->sum('refund_amount');

        // 热销TOP10（沿用原逻辑）
        $this->view->assign("goodsTopList", $goods->order('sales desc')->limit(10)->select());

        // 待审核店铺
        $this->assignconfig("shopAuthList", $shopauth->where('verify','2')->field('id,shopname,state,verify')->select());

        // 介入退款（旧逻辑保留）
        $servicesRefundList = $refund->where('state','3')->field('id,order_pay_id,price,state')->select();
        foreach ($servicesRefundList as $vo) {
            $vo['pay'] = model('app\admin\model\wanlshop\Pay')
                ->where('id', $vo['order_pay_id'])
                ->field('order_no')
                ->find();
        }
        $this->assignconfig('servicesRefundList', $servicesRefundList);

        // 输出统计数据
        $this->view->assign([
            'platformTotalAmount'      => $platformTotalAmount,
            'platformTodayAmount'      => $platformTodayAmount,
            'platformProfitTotal'      => $platformProfitTotal,
            'platformProfitSettled'    => $platformProfitSettled,

            'totalVoucherOrder'        => $totalVoucherOrder,
            'paidVoucherOrder'         => $paidVoucherOrder,
            'unpaidVoucherOrder'       => $unpaidVoucherOrder,
            'cancelVoucherOrder'       => $cancelVoucherOrder,
            'todayVoucherOrder'        => $todayVoucherOrder,

            'totalVoucher'             => $totalVoucher,
            'voucherUnused'            => $voucherUnused,
            'voucherVerified'          => $voucherVerified,
            'voucherExpired'           => $voucherExpired,
            'voucherRefunded'          => $voucherRefunded,
            'voucherVerifiedAmount'    => $voucherVerifiedAmount,
            'voucherVerifiedTodayCount'=> $voucherVerifiedTodayCount,
            'voucherVerifiedTodayAmount'=>$voucherVerifiedTodayAmount,
            'todayVerificationCount'   => $todayVerificationCount,

            'settlementPendingCount'   => $settlementPendingCount,
            'settlementPendingAmount'  => $settlementPendingAmount,
            'settlementPaidCount'      => $settlementPaidCount,
            'settlementPaidAmount'     => $settlementPaidAmount,
            'settlementPayingCount'    => $settlementPayingCount,
            'settlementFailedCount'    => $settlementFailedCount,

            'rebateTotalAmount'        => $rebateTotalAmount,
            'rebatePaidAmount'         => $rebatePaidAmount,
            'rebatePaidCount'          => $rebatePaidCount,
            'rebatePendingAmount'      => $rebatePendingAmount,
            'rebatePendingCount'       => $rebatePendingCount,
            'rebateFailedCount'        => $rebateFailedCount,

            'refundApplyingCount'      => $refundApplyingCount,
            'refundAgreeCount'         => $refundAgreeCount,
            'refundRefuseCount'        => $refundRefuseCount,
            'refundSuccessCount'       => $refundSuccessCount,
            'refundSuccessAmount'      => $refundSuccessAmount,
            'refundTodayAmount'        => $refundTodayAmount,
        ]);
        return $this->view->fetch();
    }
	
	/**
	 * 获取订单销量销售额统计数据 用于兼容旧版
	 * @param string $date
	 * @return array
	 */
	public function getSaleStatisticsData($date = '')
	{
		return $this->statis($date);
	}

	/**
	 * 获取历史趋势数据（按天统计）
	 * @return void
	 */
	public function getTrendData()
	{
		$days = $this->request->get('days', 30);
		$days = min(max((int)$days, 7), 90); // 限制7-90天

		$voucherOrder = model('app\admin\model\wanlshop\VoucherOrder');
		$voucher = model('app\admin\model\wanlshop\Voucher');
		$voucherRefund = model('app\admin\model\wanlshop\VoucherRefund');
		$user = model('app\common\model\User');

		// 生成日期列表
		$dates = [];
		$startDate = strtotime("-{$days} days");
		for ($i = 0; $i < $days; $i++) {
			$dates[] = date('Y-m-d', strtotime("+{$i} days", $startDate));
		}

		// 初始化数据结构
		$orderAmount = array_fill_keys($dates, 0);   // 每日流水
		$orderCount = array_fill_keys($dates, 0);    // 每日订单数
		$verifyAmount = array_fill_keys($dates, 0);  // 每日核销金额
		$verifyCount = array_fill_keys($dates, 0);   // 每日核销数量
		$refundAmount = array_fill_keys($dates, 0);  // 每日退款金额
		$newUsers = array_fill_keys($dates, 0);      // 每日新增用户

		$paidOrderStates = ['2', '4']; // 已支付、存在退款

		// 查询每日流水和订单数（按付款时间）
		$orderList = $voucherOrder
			->where('state', 'in', $paidOrderStates)
			->where('paymenttime', '>=', $startDate)
			->field('DATE(FROM_UNIXTIME(paymenttime)) as day, COUNT(*) as cnt, SUM(actual_payment) as amount')
			->group('day')
			->select();
		foreach ($orderList as $row) {
			if (isset($orderAmount[$row['day']])) {
				$orderAmount[$row['day']] = round($row['amount'], 2);
				$orderCount[$row['day']] = (int)$row['cnt'];
			}
		}

		// 查询每日核销数量和金额（按核销时间）
		$verifyList = $voucher
			->where('state', '2')
			->where('verifytime', '>=', $startDate)
			->field('DATE(FROM_UNIXTIME(verifytime)) as day, COUNT(*) as cnt, SUM(face_value) as amount')
			->group('day')
			->select();
		foreach ($verifyList as $row) {
			if (isset($verifyAmount[$row['day']])) {
				$verifyAmount[$row['day']] = round($row['amount'], 2);
				$verifyCount[$row['day']] = (int)$row['cnt'];
			}
		}

		// 查询每日退款金额（按退款成功时间）
		$refundList = $voucherRefund
			->where('state', '3')
			->where('updatetime', '>=', $startDate)
			->field('DATE(FROM_UNIXTIME(updatetime)) as day, SUM(refund_amount) as amount')
			->group('day')
			->select();
		foreach ($refundList as $row) {
			if (isset($refundAmount[$row['day']])) {
				$refundAmount[$row['day']] = round($row['amount'], 2);
			}
		}

		// 查询每日新增用户
		$userList = $user
			->where('jointime', '>=', $startDate)
			->field('DATE(FROM_UNIXTIME(jointime)) as day, COUNT(*) as cnt')
			->group('day')
			->select();
		foreach ($userList as $row) {
			if (isset($newUsers[$row['day']])) {
				$newUsers[$row['day']] = (int)$row['cnt'];
			}
		}

		$this->success('', '', [
			'dates' => array_keys($orderAmount),
			'orderAmount' => array_values($orderAmount),
			'orderCount' => array_values($orderCount),
			'verifyAmount' => array_values($verifyAmount),
			'verifyCount' => array_values($verifyCount),
			'refundAmount' => array_values($refundAmount),
			'newUsers' => array_values($newUsers),
		]);
	}
	
	
	/**
	 * 获取订单销量销售额统计数据
	 * @param string $date
	 * @return array
	 */
	public function statis($date = '')
	{
	    $starttime = \fast\Date::unixtime();
	    $endtime = \fast\Date::unixtime('day', 0, 'end');
	    $format = '%H:00';
		// 1.0.3修复 自动获取表前缀
		$prefix = Config::get('database.prefix');
		$list = model('app\admin\model\wanlshop\Order')
			->alias([$prefix.'wanlshop_order'=>'order', $prefix.'wanlshop_pay'=>'pay'])
			->join($prefix.'wanlshop_pay','pay.order_id = order.id')
			->where('order.createtime', 'between time', [$starttime, $endtime])
			->field('COUNT(*) AS nums,SUM(pay.price) AS amount,MIN(order.createtime) AS min_paytime,MAX(order.createtime) AS max_paytime,DATE_FORMAT(FROM_UNIXTIME(order.createtime), "' . $format . '") AS paydata')
			->group('paydata')
			->select();
	    $column = [];
	    for ($time = $starttime; $time <= $endtime;) {
	        $column[] = date("H:00", $time);
	        $time += 3600;
	    }
	    $orderSaleNums = $orderSaleAmount = array_fill_keys($column, 0);
	    foreach ($list as $vo) {
	        $orderSaleNums[$vo['paydata']] = $vo['nums'];
	        $orderSaleAmount[$vo['paydata']] = round($vo['amount'], 2);
	    }
	    $orderSaleCategory = array_keys($orderSaleAmount);
	    $orderSaleAmount = array_values($orderSaleAmount);
	    $orderSaleNums = array_values($orderSaleNums);
	    return [$orderSaleCategory, $orderSaleAmount, $orderSaleNums];
	}
}

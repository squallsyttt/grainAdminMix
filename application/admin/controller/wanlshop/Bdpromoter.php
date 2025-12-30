<?php
/**
 * BD推广员后台管理
 *
 * @icon fa fa-user-secret
 */

namespace app\admin\controller\wanlshop;

use app\common\controller\Backend;
use app\common\service\BdPromoterService;
use app\admin\service\RebateTransferService;
use think\Db;

class Bdpromoter extends Backend
{
    /**
     * 无需鉴权的方法
     */
    protected $noNeedRight = ['detail', 'stats', 'transfer', 'settlement', 'settlementData', 'monthlyStats', 'bdList', 'shopListByBd', 'markSettled'];

    public function _initialize()
    {
        parent::_initialize();
    }

    /**
     * BD推广员列表
     */
    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);

        if ($this->request->isAjax()) {
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();

            // 搜索条件
            $search = $this->request->get('search', '');

            // 构建基础条件闭包
            $buildBaseWhere = function ($query) use ($search) {
                $query->where('bd_code', 'not null')
                      ->where('bd_code', '<>', '');
                if ($search) {
                    $query->where(function ($q) use ($search) {
                        $q->where('nickname', 'like', "%{$search}%")
                          ->whereOr('bd_code', 'like', "%{$search}%")
                          ->whereOr('mobile', 'like', "%{$search}%");
                    });
                }
            };

            // 计数查询
            $total = Db::name('user')->where($buildBaseWhere)->count();

            // 列表查询
            $list = Db::name('user')
                ->where($buildBaseWhere)
                ->field('id, nickname, avatar, mobile, bd_code, bd_apply_time')
                ->order($sort ?: 'bd_apply_time', $order ?: 'desc')
                ->limit($offset, $limit)
                ->select();

            // 补充统计数据
            foreach ($list as &$row) {
                // 邀请店铺数
                $row['shop_count'] = Db::name('bd_shop_bindlog')
                    ->where('bd_user_id', $row['id'])
                    ->count();

                // 累计佣金
                $row['total_commission'] = Db::name('bd_commission_log')
                    ->where('bd_user_id', $row['id'])
                    ->where('type', 'earn')
                    ->where('settle_status', '<>', 'cancelled')
                    ->sum('commission_amount') ?: 0;

                // 当前周期信息
                $period = Db::name('bd_promoter_period')
                    ->where('user_id', $row['id'])
                    ->where('status', 'active')
                    ->order('period_index', 'desc')
                    ->find();

                $row['current_rate'] = $period ? (float)$period['current_rate'] : 0;
                $row['period_index'] = $period ? (int)$period['period_index'] : 0;
                $row['period_shop_count'] = $period ? (int)$period['shop_count'] : 0;

                // 格式化时间
                $row['bd_apply_time_text'] = $row['bd_apply_time'] ? date('Y-m-d H:i:s', $row['bd_apply_time']) : '-';
                $row['current_rate_text'] = $row['current_rate'] > 0 ? ($row['current_rate'] * 1000) . '‰' : '未激活';
            }
            unset($row);

            $result = ['total' => $total, 'rows' => $list];
            return json($result);
        }

        return $this->view->fetch();
    }

    /**
     * BD推广员详情
     */
    public function detail($ids = null)
    {
        $id = $ids ?: $this->request->get('ids');
        if (!$id) {
            $this->error(__('参数错误'));
        }

        $bdService = new BdPromoterService();
        $info = $bdService->getBdPromoterInfo($id);

        if (!$info['is_bd_promoter']) {
            $this->error(__('该用户不是BD推广员'));
        }

        // 获取用户基本信息
        $user = Db::name('user')
            ->where('id', $id)
            ->field('id, nickname, avatar, mobile, bd_code, bd_apply_time')
            ->find();

        // 获取周期列表
        $periods = $bdService->getPeriods($id);

        // 获取邀请店铺列表
        $shops = $bdService->getInvitedShops($id, 1, 100);

        // 获取佣金明细（最近50条）
        $commissions = $bdService->getCommissionLogs($id, 1, 50);

        $this->view->assign([
            'user' => $user,
            'info' => $info,
            'periods' => $periods,
            'shops' => $shops['list'],
            'commissions' => $commissions['list']
        ]);

        return $this->view->fetch();
    }

    /**
     * BD统计报表
     */
    public function stats()
    {
        if ($this->request->isAjax()) {
            $type = $this->request->get('type', 'today');

            // 计算时间范围
            $now = time();
            switch ($type) {
                case 'today':
                    $startTime = strtotime(date('Y-m-d'));
                    $endTime = $now;
                    break;
                case '3days':
                    $startTime = strtotime('-2 days', strtotime(date('Y-m-d')));
                    $endTime = $now;
                    break;
                case '7days':
                    $startTime = strtotime('-6 days', strtotime(date('Y-m-d')));
                    $endTime = $now;
                    break;
                case '30days':
                    $startTime = strtotime('-29 days', strtotime(date('Y-m-d')));
                    $endTime = $now;
                    break;
                default:
                    $startTime = $this->request->get('start_time/d', strtotime(date('Y-m-d')));
                    $endTime = $this->request->get('end_time/d', $now);
            }

            // 统计数据
            $stats = [
                // 新增BD数
                'new_bd_count' => Db::name('user')
                    ->where('bd_apply_time', '>=', $startTime)
                    ->where('bd_apply_time', '<=', $endTime)
                    ->where('bd_code', 'not null')
                    ->count(),

                // 新增店铺绑定数
                'new_shop_bind_count' => Db::name('bd_shop_bindlog')
                    ->where('createtime', '>=', $startTime)
                    ->where('createtime', '<=', $endTime)
                    ->count(),

                // 总佣金（所有收入记录，与流水明细统计口径一致）
                'total_commission' => Db::name('bd_commission_log')
                    ->where('createtime', '>=', $startTime)
                    ->where('createtime', '<=', $endTime)
                    ->where('type', 'earn')
                    ->sum('commission_amount') ?: 0,

                // 扣减佣金
                'deduct_commission' => Db::name('bd_commission_log')
                    ->where('createtime', '>=', $startTime)
                    ->where('createtime', '<=', $endTime)
                    ->where('type', 'deduct')
                    ->sum('commission_amount') ?: 0,

                // 活跃BD数（本时段有佣金收入的BD）
                'active_bd_count' => Db::name('bd_commission_log')
                    ->where('createtime', '>=', $startTime)
                    ->where('createtime', '<=', $endTime)
                    ->where('type', 'earn')
                    ->group('bd_user_id')
                    ->count(),
            ];

            // 净佣金
            $stats['net_commission'] = round($stats['total_commission'] - $stats['deduct_commission'], 2);

            // BD排行榜（按佣金排序，与流水明细统计口径一致）
            $topBdList = Db::name('bd_commission_log')
                ->alias('c')
                ->join('user u', 'u.id = c.bd_user_id', 'LEFT')
                ->where('c.createtime', '>=', $startTime)
                ->where('c.createtime', '<=', $endTime)
                ->where('c.type', 'earn')
                ->field('c.bd_user_id, u.nickname, u.bd_code, SUM(c.commission_amount) as total_commission')
                ->group('c.bd_user_id')
                ->order('total_commission', 'desc')
                ->limit(10)
                ->select();

            $stats['top_bd_list'] = $topBdList;

            $this->success('ok', null, $stats);
        }

        return $this->view->fetch();
    }

    /**
     * BD佣金打款
     *
     * @param int $commission_log_id 佣金明细ID
     */
    public function transfer()
    {
        if (!$this->request->isPost()) {
            $this->error(__('请求方式错误'));
        }

        $commissionLogId = $this->request->post('commission_log_id/d');
        if ($commissionLogId <= 0) {
            $this->error(__('参数错误'));
        }

        $bdService = new BdPromoterService();

        // 1. 创建返利记录（后台管理员可跳过时间限制检查）
        $createResult = $bdService->createRebateRecord($commissionLogId, true);
        if (!$createResult['success']) {
            $this->error($createResult['message']);
        }

        $rebateId = $createResult['rebate_id'];

        // 2. 执行打款
        $transferService = new RebateTransferService();
        $transferResult = $transferService->transferBdCommission($rebateId);

        if (!$transferResult['success']) {
            $this->error($transferResult['message']);
        }

        // 返回打款结果
        $data = $transferResult['data'];
        $message = '打款请求已发送';
        if ($data['need_user_confirm']) {
            $message = '打款已发起，等待用户确认收款';
        } elseif ($data['transfer_state'] === 'SUCCESS') {
            $message = '打款成功';
        }

        $this->success($message, $data);
    }

    /**
     * 批量BD佣金打款
     *
     * @param int $bd_user_id BD推广员用户ID
     */
    public function batchTransfer()
    {
        if (!$this->request->isPost()) {
            $this->error(__('请求方式错误'));
        }

        $bdUserId = $this->request->post('bd_user_id/d');
        if ($bdUserId <= 0) {
            $this->error(__('参数错误'));
        }

        $bdService = new BdPromoterService();
        $transferService = new RebateTransferService();

        // 创建所有可打款的返利记录
        $createResult = $bdService->batchCreateRebateRecords($bdUserId);

        $successCount = 0;
        $failedCount = 0;
        $errors = [];

        // 获取所有待打款的返利记录
        $rebates = Db::name('wanlshop_voucher_rebate')
            ->where('rebate_type', 'bd_promoter')
            ->where('user_id', $bdUserId)
            ->where('payment_status', 'unpaid')
            ->select();

        foreach ($rebates as $rebate) {
            $transferResult = $transferService->transferBdCommission((int)$rebate['id']);
            if ($transferResult['success']) {
                $successCount++;
            } else {
                $failedCount++;
                $errors[] = [
                    'rebate_id' => $rebate['id'],
                    'message' => $transferResult['message']
                ];
            }
        }

        if ($successCount > 0) {
            $this->success("批量打款完成：成功 {$successCount} 笔，失败 {$failedCount} 笔", [
                'success_count' => $successCount,
                'failed_count' => $failedCount,
                'errors' => $errors
            ]);
        } else {
            $this->error('批量打款失败：' . ($errors[0]['message'] ?? '无可打款记录'));
        }
    }

    /**
     * 结算统计页面
     */
    public function settlement()
    {
        return $this->view->fetch();
    }

    /**
     * 获取BD推广员列表（下拉选择用）
     */
    public function bdList()
    {
        $list = Db::name('user')
            ->where('bd_code', 'not null')
            ->where('bd_code', '<>', '')
            ->field('id, nickname, mobile, bd_code')
            ->order('bd_apply_time', 'desc')
            ->select();

        $this->success('ok', null, $list);
    }

    /**
     * 根据BD获取其邀请的店铺列表
     */
    public function shopListByBd()
    {
        $bdUserId = $this->request->get('bd_user_id/d', 0);
        if (!$bdUserId) {
            $this->success('ok', null, []);
            return;
        }

        $list = Db::name('bd_shop_bindlog')
            ->alias('b')
            ->join('wanlshop_shop s', 's.id = b.shop_id', 'LEFT')
            ->where('b.bd_user_id', $bdUserId)
            ->field('b.shop_id as id, s.shopname as name')
            ->select();

        $this->success('ok', null, $list);
    }

    /**
     * 结算统计数据
     */
    public function settlementData()
    {
        $bdUserId = $this->request->get('bd_user_id/d', 0);
        $shopId = $this->request->get('shop_id/d', 0);
        $startDate = $this->request->get('start_date', '');
        $endDate = $this->request->get('end_date', '');

        // 构建查询条件
        $where = [];
        if ($bdUserId > 0) {
            $where['c.bd_user_id'] = $bdUserId;
        }
        if ($shopId > 0) {
            $where['c.shop_id'] = $shopId;
        }

        // 时间范围
        $startTime = $startDate ? strtotime($startDate . ' 00:00:00') : strtotime(date('Y-m-01'));
        $endTime = $endDate ? strtotime($endDate . ' 23:59:59') : time();

        // 获取流水明细，JOIN 周期表和核销券表获取详细信息
        $list = Db::name('bd_commission_log')
            ->alias('c')
            ->join('user u', 'u.id = c.bd_user_id', 'LEFT')
            ->join('wanlshop_shop s', 's.id = c.shop_id', 'LEFT')
            ->join('bd_promoter_period p', 'p.id = c.period_id', 'LEFT')
            ->join('wanlshop_voucher v', 'v.id = c.voucher_id', 'LEFT')
            ->where($where)
            ->where('c.createtime', '>=', $startTime)
            ->where('c.createtime', '<=', $endTime)
            ->field('c.*, u.nickname as bd_nickname, u.bd_code, s.shopname, p.period_index, p.current_rate as period_rate, v.voucher_no, v.goods_title, v.face_value as voucher_face_value')
            ->order('c.createtime', 'desc')
            ->select();

        // 统计汇总
        $summary = [
            'earn_count' => 0,
            'earn_amount' => 0,
            'deduct_count' => 0,
            'deduct_amount' => 0,
            'net_amount' => 0,
            'settled_amount' => 0,
            'pending_amount' => 0,
        ];

        foreach ($list as &$row) {
            $row['createtime_text'] = date('Y-m-d H:i:s', $row['createtime']);

            // 类型描述：收入 / 退款扣减
            if ($row['type'] === 'earn') {
                $row['type_text'] = '收入';
                $row['type_desc'] = '核销返佣';
            } else {
                $row['type_text'] = '扣减';
                $row['type_desc'] = $row['refund_id'] ? '订单退款' : '其他扣减';
            }

            // 结算状态
            $row['settle_status_text'] = $this->getSettleStatusText($row['settle_status']);

            // 周期信息
            $row['period_index'] = $row['period_index'] ?: '-';
            $row['period_index_text'] = $row['period_index'] !== '-' ? '第' . $row['period_index'] . '周期' : '-';

            // 比例：优先使用记录时的比例，其次用周期比例
            $rate = (float)$row['commission_rate'];
            $row['rate_text'] = $rate > 0 ? ($rate * 1000) . '‰' : '-';

            // 计算公式
            $orderAmount = (float)$row['order_amount'];
            $commissionAmount = (float)$row['commission_amount'];
            if ($orderAmount > 0 && $rate > 0) {
                $row['formula'] = '¥' . number_format($orderAmount, 2) . ' × ' . ($rate * 1000) . '‰ = ¥' . number_format($commissionAmount, 2);
            } else {
                $row['formula'] = '¥' . number_format($commissionAmount, 2);
            }

            // 核销券信息
            $row['voucher_info'] = '';
            if (!empty($row['voucher_no'])) {
                $row['voucher_info'] = $row['goods_title'] . ' (¥' . number_format((float)$row['voucher_face_value'], 2) . ')';
            }

            if ($row['type'] === 'earn') {
                $summary['earn_count']++;
                $summary['earn_amount'] += $commissionAmount;
                if ($row['settle_status'] === 'settled') {
                    $summary['settled_amount'] += $commissionAmount;
                } elseif ($row['settle_status'] !== 'cancelled') {
                    $summary['pending_amount'] += $commissionAmount;
                }
            } else {
                $summary['deduct_count']++;
                $summary['deduct_amount'] += $commissionAmount;
            }
        }
        unset($row);

        $summary['net_amount'] = round($summary['earn_amount'] - $summary['deduct_amount'], 2);
        $summary['earn_amount'] = round($summary['earn_amount'], 2);
        $summary['deduct_amount'] = round($summary['deduct_amount'], 2);
        $summary['settled_amount'] = round($summary['settled_amount'], 2);
        $summary['pending_amount'] = round($summary['pending_amount'], 2);

        $this->success('ok', null, [
            'list' => $list,
            'summary' => $summary,
            'filter' => [
                'bd_user_id' => $bdUserId,
                'shop_id' => $shopId,
                'start_date' => date('Y-m-d', $startTime),
                'end_date' => date('Y-m-d', $endTime),
            ]
        ]);
    }

    /**
     * 月度收益统计
     */
    public function monthlyStats()
    {
        $bdUserId = $this->request->get('bd_user_id/d', 0);
        $shopId = $this->request->get('shop_id/d', 0);
        $year = $this->request->get('year/d', date('Y'));

        // 构建查询条件
        $where = [];
        if ($bdUserId > 0) {
            $where['bd_user_id'] = $bdUserId;
        }
        if ($shopId > 0) {
            $where['shop_id'] = $shopId;
        }

        // 获取该年所有月份的统计
        $monthlyData = [];
        for ($month = 1; $month <= 12; $month++) {
            $startTime = strtotime("$year-$month-01 00:00:00");
            $endTime = strtotime(date('Y-m-t 23:59:59', $startTime));

            // 如果是未来月份，跳过
            if ($startTime > time()) {
                continue;
            }

            $earnAmount = Db::name('bd_commission_log')
                ->where($where)
                ->where('createtime', '>=', $startTime)
                ->where('createtime', '<=', $endTime)
                ->where('type', 'earn')
                ->where('settle_status', '<>', 'cancelled')
                ->sum('commission_amount') ?: 0;

            $deductAmount = Db::name('bd_commission_log')
                ->where($where)
                ->where('createtime', '>=', $startTime)
                ->where('createtime', '<=', $endTime)
                ->where('type', 'deduct')
                ->sum('commission_amount') ?: 0;

            $settledAmount = Db::name('bd_commission_log')
                ->where($where)
                ->where('createtime', '>=', $startTime)
                ->where('createtime', '<=', $endTime)
                ->where('type', 'earn')
                ->where('settle_status', 'settled')
                ->sum('commission_amount') ?: 0;

            $monthlyData[] = [
                'month' => $month,
                'month_text' => $month . '月',
                'earn_amount' => round($earnAmount, 2),
                'deduct_amount' => round($deductAmount, 2),
                'net_amount' => round($earnAmount - $deductAmount, 2),
                'settled_amount' => round($settledAmount, 2),
                'pending_amount' => round($earnAmount - $deductAmount - $settledAmount, 2),
            ];
        }

        // 年度汇总
        $yearSummary = [
            'earn_amount' => 0,
            'deduct_amount' => 0,
            'net_amount' => 0,
            'settled_amount' => 0,
            'pending_amount' => 0,
        ];
        foreach ($monthlyData as $m) {
            $yearSummary['earn_amount'] += $m['earn_amount'];
            $yearSummary['deduct_amount'] += $m['deduct_amount'];
            $yearSummary['net_amount'] += $m['net_amount'];
            $yearSummary['settled_amount'] += $m['settled_amount'];
            $yearSummary['pending_amount'] += $m['pending_amount'];
        }

        $this->success('ok', null, [
            'year' => $year,
            'monthly' => $monthlyData,
            'summary' => $yearSummary,
        ]);
    }

    /**
     * 标记为已线下结算
     */
    public function markSettled()
    {
        if (!$this->request->isPost()) {
            $this->error(__('请求方式错误'));
        }

        $ids = $this->request->post('ids');
        $remark = $this->request->post('remark', '线下结算');

        if (empty($ids)) {
            $this->error(__('请选择要结算的记录'));
        }

        $idArr = is_array($ids) ? $ids : explode(',', $ids);

        $updateCount = Db::name('bd_commission_log')
            ->whereIn('id', $idArr)
            ->where('settle_status', 'pending')
            ->where('type', 'earn')
            ->update([
                'settle_status' => 'settled',
                'remark' => Db::raw("CONCAT(IFNULL(remark,''), ' [线下结算: " . date('Y-m-d H:i:s') . " - " . addslashes($remark) . "]')")
            ]);

        if ($updateCount > 0) {
            $this->success("已标记 {$updateCount} 条记录为已结算");
        } else {
            $this->error('没有可结算的记录（可能已结算或已取消）');
        }
    }

    /**
     * 获取结算状态文本
     */
    private function getSettleStatusText($status)
    {
        $map = [
            'pending' => '待结算',
            'settling' => '结算中',
            'settled' => '已结算',
            'cancelled' => '已取消',
        ];
        return $map[$status] ?? $status;
    }
}
